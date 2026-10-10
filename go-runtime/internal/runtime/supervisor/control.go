package supervisor

import (
	"context"
	"errors"
	"log/slog"
	"time"

	"github.com/jh/telegram-bots/botd/internal/storage/controlqueue"
	"github.com/jh/telegram-bots/botd/internal/ws/events"
)

var errUnknownAction = errors.New("unknown control action")

// controlLoop consumes the bot_control queue.
func (s *Supervisor) controlLoop(ctx context.Context) {
	t := time.NewTicker(s.controlPollInterval)
	defer t.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			s.processNext(ctx)
		}
	}
}

func (s *Supervisor) processNext(ctx context.Context) {
	cmd, err := s.ctrlRepo.NextPending(ctx)
	if err != nil {
		slog.Warn("next pending control command failed", "err", err.Error())
		return
	}
	if cmd == nil {
		return
	}

	claimed, err := s.ctrlRepo.Claim(ctx, cmd.ID)
	if err != nil {
		slog.Warn("claim control command failed", "id", cmd.ID, "err", err.Error())
		return
	}
	if !claimed {
		return
	}

	result := s.execute(ctx, cmd)

	status := "done"
	if result.err != nil {
		status = "error"
	}
	if err := s.ctrlRepo.Finish(ctx, cmd.ID, status, result.message); err != nil {
		slog.Warn("finish control command failed", "id", cmd.ID, "err", err.Error())
	}

	// Push lifecycle event (result text is operational, no secrets).
	s.deps.Publisher.Publish(events.New(events.BotLifecycle, map[string]any{
		"bot_name":   cmd.BotName,
		"action":     string(cmd.Action),
		"state":      status,
		"control_id": cmd.ID,
		"result":     result.message,
	}))
}

type execResult struct {
	message string
	err     error
}

func (s *Supervisor) execute(ctx context.Context, cmd *controlqueue.Command) execResult {
	switch cmd.Action {
	case "start":
		if err := s.StartBot(ctx, cmd.BotName); err != nil {
			return execResult{message: err.Error(), err: err}
		}
		return execResult{message: "started"}
	case "stop":
		if err := s.StopBot(ctx, cmd.BotName); err != nil {
			return execResult{message: err.Error(), err: err}
		}
		return execResult{message: "stopped"}
	case "restart":
		if err := s.RestartBot(ctx, cmd.BotName); err != nil {
			return execResult{message: err.Error(), err: err}
		}
		return execResult{message: "restarted"}
	default:
		return execResult{message: "unknown action", err: errUnknownAction}
	}
}
