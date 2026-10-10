// Package supervisor manages the bot registry, start/stop/restart, the
// reconciler and the control-queue consumer. It replaces manager.php
// daemon/watcher and control_worker.php.
package supervisor

import (
	"context"
	"log/slog"
	"sync"
	"time"

	"github.com/jh/telegram-bots/botd/internal/runtime/runner"
	"github.com/jh/telegram-bots/botd/internal/storage/bots"
	"github.com/jh/telegram-bots/botd/internal/storage/controlqueue"
)

// circuitBreakerCooldown is the wait after a runner circuit-breaks before the
// reconciler may restart it (DECISIONS #4).
const circuitBreakerCooldown = 60 * time.Second

// Supervisor owns all running bots.
type Supervisor struct {
	deps runner.Deps

	botsRepo *bots.Repo
	ctrlRepo *controlqueue.Repo

	reconcileInterval   time.Duration
	controlPollInterval time.Duration
	botdOnly            map[string]bool

	mu      sync.Mutex
	runners map[string]*runner.Runner
	// exitAt records when a runner last exited, for circuit-breaker cooldown.
	exitAt map[string]time.Time
}

// New creates a Supervisor.
func New(deps runner.Deps, reconcileIntervalS, controlPollIntervalS int, botdOnly []string) *Supervisor {
	s := &Supervisor{
		deps:                deps,
		botsRepo:            bots.New(deps.DB),
		ctrlRepo:            controlqueue.New(deps.DB),
		reconcileInterval:   time.Duration(reconcileIntervalS) * time.Second,
		controlPollInterval: time.Duration(controlPollIntervalS) * time.Second,
		runners:             map[string]*runner.Runner{},
		exitAt:              map[string]time.Time{},
	}
	s.botdOnly = map[string]bool{}
	for _, b := range botdOnly {
		s.botdOnly[b] = true
	}
	return s
}

// Run starts the background loops and blocks until ctx is canceled.
func (s *Supervisor) Run(ctx context.Context) {
	// Recover stale running control commands at startup.
	if err := s.ctrlRepo.RecoverRunning(ctx); err != nil {
		slog.Warn("recover running control commands failed", "err", err.Error())
	}

	go s.reconcileLoop(ctx)
	go s.controlLoop(ctx)

	<-ctx.Done()
	s.stopAll(context.Background())
}

func (s *Supervisor) allowed(name string) bool {
	if len(s.botdOnly) == 0 {
		return true
	}
	return s.botdOnly[name]
}

// StartBot starts a bot if not already running.
func (s *Supervisor) StartBot(ctx context.Context, name string) error {
	if !s.allowed(name) {
		slog.Info("bot not in BOTD_ONLY whitelist, skip start", "bot", name)
		return nil
	}

	s.mu.Lock()
	if _, running := s.runners[name]; running {
		s.mu.Unlock()
		return nil
	}
	r := runner.New(name, s.deps)
	s.runners[name] = r
	s.mu.Unlock()

	r.Start(ctx)

	// Track exit for cooldown without blocking.
	go func(name string, r *runner.Runner) {
		<-r.Done()
		s.mu.Lock()
		s.exitAt[name] = time.Now()
		// Only remove from registry if this is still the same runner.
		if cur := s.runners[name]; cur == r {
			delete(s.runners, name)
		}
		s.mu.Unlock()
	}(name, r)

	return nil
}

// StopBot stops a running bot.
func (s *Supervisor) StopBot(ctx context.Context, name string) error {
	s.mu.Lock()
	r := s.runners[name]
	s.mu.Unlock()
	if r == nil {
		return nil
	}
	r.Stop(ctx)
	s.mu.Lock()
	delete(s.runners, name)
	s.mu.Unlock()
	return nil
}

// RestartBot stops then starts a bot (avoids 409 dual consumers).
func (s *Supervisor) RestartBot(ctx context.Context, name string) error {
	if err := s.StopBot(ctx, name); err != nil {
		return err
	}
	return s.StartBot(ctx, name)
}

// Snapshot returns the names of currently-registered runners.
func (s *Supervisor) Snapshot() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := make([]string, 0, len(s.runners))
	for name := range s.runners {
		out = append(out, name)
	}
	return out
}

func (s *Supervisor) stopAll(ctx context.Context) {
	s.mu.Lock()
	runnersList := make([]*runner.Runner, 0, len(s.runners))
	for _, r := range s.runners {
		runnersList = append(runnersList, r)
	}
	s.mu.Unlock()

	var wg sync.WaitGroup
	for _, r := range runnersList {
		wg.Add(1)
		go func(r *runner.Runner) {
			defer wg.Done()
			r.Stop(ctx)
		}(r)
	}
	wg.Wait()
}
