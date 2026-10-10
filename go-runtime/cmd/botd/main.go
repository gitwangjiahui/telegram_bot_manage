// Command botd runs the Go Telegram bot runtime: multi-bot long polling,
// supervision, captcha pre-generation pool, WS push and control-queue
// consumption, all in a single process.
package main

import (
	"context"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/jh/telegram-bots/botd/internal/captcha/render"
	"github.com/jh/telegram-bots/botd/internal/config/appconfig"
	"github.com/jh/telegram-bots/botd/internal/config/loader"
	"github.com/jh/telegram-bots/botd/internal/logging"
	"github.com/jh/telegram-bots/botd/internal/runtime/runner"
	"github.com/jh/telegram-bots/botd/internal/runtime/supervisor"
	"github.com/jh/telegram-bots/botd/internal/storage/db"
	"github.com/jh/telegram-bots/botd/internal/storage/statusrepo"
	tgtransport "github.com/jh/telegram-bots/botd/internal/tg/transport"
	"github.com/jh/telegram-bots/botd/internal/ws/auth"
	"github.com/jh/telegram-bots/botd/internal/ws/events"
	"github.com/jh/telegram-bots/botd/internal/ws/hub"
	wsserver "github.com/jh/telegram-bots/botd/internal/ws/server"
)

func main() {
	cfg := loader.FromEnv()
	logging.Init(cfg.LogLevel)

	rootCtx, rootCancel := context.WithCancel(context.Background())
	defer rootCancel()

	// DB pool. Failure to open is fatal at construction, but an unreachable
	// server is tolerated: Ping is retried and the runtime never exits.
	database, err := db.Open(cfg.DSN())
	if err != nil {
		slog.Error("open db pool failed", "err", err.Error())
		os.Exit(1)
	}
	defer database.Close()

	// Config table cache (60s TTL).
	configCache := appconfig.New(database)

	// Shared telegram transport manager (mandatory proxy).
	tm := tgtransport.NewManager(cfg.AllowDirect)

	// Event bus -> WS hub.
	bus := events.NewBus()
	h := hub.New()
	bus.Subscribe(func(e events.Event) { h.Publish(e) })

	// WS auth: fail-closed unless WS_AUTH=off.
	wsAuthOff := cfg.WSAuth == "off"
	wsAuth := auth.New(cfg.JWTSecret, wsAuthOff)
	if wsAuthOff {
		slog.Warn("WS authentication disabled (WS_AUTH=off); debug only")
	}

	statusRepo := statusrepo.New(database)
	wsSrv := wsserver.New(h, wsAuth, func() map[string]any {
		ctx, cancel := context.WithTimeout(rootCtx, 5*time.Second)
		defer cancel()
		return statusRepo.All(ctx)
	})

	deps := runner.Deps{
		DB:        database,
		Config:    configCache,
		Transport: tm,
		Publisher: bus,
		Renderer:  render.New(),
	}
	sup := supervisor.New(deps, cfg.ReconcileInterval, cfg.ControlPollInterval, cfg.BotdOnly)

	httpServer := &http.Server{
		Addr:              cfg.Listen,
		Handler:           wsSrv.Handler(pingDB(database), func() int { return len(sup.Snapshot()) }),
		ReadHeaderTimeout: 10 * time.Second,
	}

	go func() {
		slog.Info("botd http/ws listening", "addr", cfg.Listen)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			slog.Error("http server stopped", "err", err.Error())
		}
	}()

	// Signal handling: SIGTERM/SIGINT cancels the whole supervisor.
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGTERM, syscall.SIGINT)

	go func() {
		sig := <-sigCh
		slog.Info("signal received, shutting down", "signal", sig.String())
		rootCancel()

		shutdownCtx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		_ = httpServer.Shutdown(shutdownCtx)
	}()

	sup.Run(rootCtx)
	slog.Info("botd stopped")
}

func pingDB(database interface {
	PingContext(context.Context) error
}) func(context.Context) bool {
	return func(ctx context.Context) bool {
		return database.PingContext(ctx) == nil
	}
}
