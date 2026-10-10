package supervisor

import (
	"context"
	"log/slog"
	"time"
)

// reconcileLoop periodically reconciles bots.is_active with running instances.
func (s *Supervisor) reconcileLoop(ctx context.Context) {
	t := time.NewTicker(s.reconcileInterval)
	defer t.Stop()

	s.reconcileOnce(ctx) // initial reconciliation: start all active bots
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			s.reconcileOnce(ctx)
		}
	}
}

func (s *Supervisor) reconcileOnce(ctx context.Context) {
	all, err := s.botsRepo.ListAll(ctx)
	if err != nil {
		slog.Warn("reconcile list bots failed, retry next tick", "err", err.Error())
		return
	}

	for _, b := range all {
		if !s.allowed(b.BotName) {
			continue
		}

		s.mu.Lock()
		_, running := s.runners[b.BotName]
		exitAt, hadExit := s.exitAt[b.BotName]
		s.mu.Unlock()

		if b.IsActive && !running {
			// Respect circuit-breaker cooldown after a prior exit.
			if hadExit && time.Since(exitAt) < circuitBreakerCooldown {
				continue
			}
			if err := s.StartBot(ctx, b.BotName); err != nil {
				slog.Warn("reconcile start bot failed", "bot", b.BotName,
					"err", err.Error())
			}
			continue
		}

		if !b.IsActive && running {
			if err := s.StopBot(ctx, b.BotName); err != nil {
				slog.Warn("reconcile stop bot failed", "bot", b.BotName,
					"err", err.Error())
			}
		}
	}
}
