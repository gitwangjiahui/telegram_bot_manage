// Package runner implements BotRunner: it composes poller/processor/reporter/
// pool maintainer for one bot and runs the manager.php-equivalent main loop
// with three-way error handling and a 10-business-error circuit breaker.
package runner

import (
	"context"
	"database/sql"
	"log/slog"
	"sync"
	"time"

	"github.com/jh/telegram-bots/botd/internal/captcha/render"
	"github.com/jh/telegram-bots/botd/internal/config/appconfig"
	"github.com/jh/telegram-bots/botd/internal/runtime/poller"
	"github.com/jh/telegram-bots/botd/internal/runtime/poolmaintainer"
	"github.com/jh/telegram-bots/botd/internal/runtime/reporter"
	"github.com/jh/telegram-bots/botd/internal/tg/transport"
	"github.com/jh/telegram-bots/botd/internal/ws/events"
)

const (
	maxBusinessErrors = 10
	maxBackoff        = 30 * time.Second
)

// Deps are process-wide shared dependencies every runner builds on.
type Deps struct {
	DB        *sql.DB
	Config    *appconfig.Cache
	Transport *transport.Manager
	Publisher events.Publisher
	Renderer  *render.Renderer
}

// Runner runs one bot.
type Runner struct {
	botName string
	deps    Deps

	cancel context.CancelFunc
	done   chan struct{}

	mu sync.Mutex
}

// New creates a Runner for a bot name.
func New(botName string, deps Deps) *Runner {
	return &Runner{botName: botName, deps: deps}
}

// Name returns the bot name.
func (r *Runner) Name() string { return r.botName }

// Done returns a channel closed when the run loop exits.
func (r *Runner) Done() <-chan struct{} { return r.done }

// Start launches the bot. Config-load failures are retried inside the loop.
func (r *Runner) Start(parent context.Context) {
	ctx, cancel := context.WithCancel(parent)
	r.cancel = cancel
	r.done = make(chan struct{})
	go r.loop(ctx)
}

// Stop cancels the bot and waits for the run loop to exit.
func (r *Runner) Stop(parent context.Context) {
	r.mu.Lock()
	if r.cancel != nil {
		r.cancel()
	}
	r.mu.Unlock()

	if r.done != nil {
		select {
		case <-r.done:
		case <-parent.Done():
		}
	}
}

func (r *Runner) loop(ctx context.Context) {
	defer close(r.done)

	comp, ok := r.build(ctx)
	if !ok {
		return
	}

	go comp.reporter.RunTicker(ctx)
	if comp.maintainer != nil {
		go comp.maintainer.Run(ctx)
	}

	var (
		businessErrors int
		dbFails        int
		netFails       int
	)

	for ctx.Err() == nil {
		res := comp.poller.RunOnce(ctx)

		if res.Err != nil {
			handled := r.handleError(ctx, comp.reporter, res.Err,
				&businessErrors, &dbFails, &netFails)
			if !handled {
				// Circuit breaker fired: runner exits.
				return
			}
			continue
		}

		// Success.
		if dbFails > 0 || netFails > 0 || businessErrors > 0 {
			slog.Info("connection recovered", "bot", r.botName)
		}
		dbFails = 0
		netFails = 0
		businessErrors = 0
		comp.reporter.ClearError() // DECISIONS #3

		if !res.HadUpdates {
			poller.EmptyPollSleep(ctx)
		}
		comp.reporter.Touch(ctx, "polling")
	}

	markStopped(comp.reporter)
}

// handleError applies the three-way classification. Returns false when the
// circuit breaker fired (caller must exit the loop).
func (r *Runner) handleError(ctx context.Context, rep *reporter.Reporter, err error,
	businessErrors, dbFails, netFails *int) bool {
	switch classifyErr(err) {
	case classDB:
		*dbFails++
		rep.SetError("数据库异常: " + err.Error())
		if *dbFails == 1 || *dbFails%10 == 0 {
			slog.Warn("db error, waiting for recovery", "bot", r.botName, "err", err.Error())
		}
		return interruptibleSleep(ctx, backoff(*dbFails))

	case classNetwork:
		*netFails++
		rep.SetError("Telegram 网络异常: " + err.Error())
		if *netFails == 1 || *netFails%10 == 0 {
			slog.Warn("telegram network error, retrying", "bot", r.botName, "err", err.Error())
		}
		return interruptibleSleep(ctx, backoff(*netFails))

	default: // business
		*businessErrors++
		*netFails = 0
		rep.SetError("业务错误: " + err.Error())
		slog.Warn("telegram business error", "bot", r.botName,
			"count", *businessErrors, "err", err.Error())

		if *businessErrors >= maxBusinessErrors {
			slog.Error("circuit breaker: max business errors reached, runner exiting",
				"bot", r.botName)
			markStopped(rep)
			return false
		}
		return interruptibleSleep(ctx, backoff(*businessErrors))
	}
}

func markStopped(rep *reporter.Reporter) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	rep.MarkStopped(ctx)
}

// build loads config and constructs components, retrying until success or cancel.
func (r *Runner) build(ctx context.Context) (*components, bool) {
	fails := 0
	startedAt := time.Now().Format("2006-01-02 15:04:05")
	for ctx.Err() == nil {
		comp, err := buildComponents(ctx, r.botName, startedAt, r.deps)
		if err == nil && comp != nil {
			return comp, true
		}
		if err != nil {
			fails++
			if fails == 1 || fails%10 == 0 {
				slog.Warn("build bot components failed", "bot", r.botName,
					"err", err.Error())
			}
		}
		if !interruptibleSleep(ctx, backoff(fails)) {
			return nil, false
		}
	}
	return nil, false
}

func backoff(n int) time.Duration {
	d := time.Duration(n) * 2 * time.Second
	if n <= 0 {
		d = 2 * time.Second
	}
	if d > maxBackoff {
		d = maxBackoff
	}
	return d
}

func interruptibleSleep(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}

// satisfy unused-import guard for poolmaintainer (used in components.go).
var _ = poolmaintainer.New
