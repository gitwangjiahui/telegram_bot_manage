// Package poller implements getUpdates long polling with the process-then-confirm
// offset sequence (docs 3.2, at-least-once).
package poller

import (
	"context"
	"log/slog"
	"time"

	"github.com/jh/telegram-bots/botd/internal/config/appconfig"
	"github.com/jh/telegram-bots/botd/internal/runtime/processor"
	"github.com/jh/telegram-bots/botd/internal/tg/client"
)

// Poller is the single poll/process goroutine for one bot.
type Poller struct {
	botID     int
	botName   string
	client    *client.Client
	config    *appconfig.Cache
	processor *processor.Processor
}

// New creates a Poller.
func New(botID int, botName string, c *client.Client, cfg *appconfig.Cache,
	proc *processor.Processor) *Poller {
	return &Poller{
		botID:     botID,
		botName:   botName,
		client:    c,
		config:    cfg,
		processor: proc,
	}
}

// Result signals how an iteration ended, used by the runner for error handling.
type Result struct {
	// Err is the getUpdates failure (nil on success including empty poll).
	Err error
	// HadUpdates indicates at least one update was processed.
	HadUpdates bool
}

// RunOnce performs a single getUpdates/process/confirm cycle.
// Offset is loaded from the config cache (authoritative memory site).
func (p *Poller) RunOnce(ctx context.Context) Result {
	var offset int64
	if saved, ok := p.config.GetOffset(ctx, p.botID); ok {
		offset = saved
	}

	updates, err := p.client.GetUpdates(ctx, offset+1, 8)
	if err != nil {
		return Result{Err: err}
	}

	if len(updates) == 0 {
		// Empty poll: the runner sleeps 0.3s.
		return Result{Err: nil, HadUpdates: false}
	}

	var maxID int64
	for i := range updates {
		u := &updates[i]
		if u.UpdateID > maxID {
			maxID = u.UpdateID
		}
		// Per-update failure is contained; the update is still confirmed with the batch.
		p.processor.Process(ctx, u)
	}

	// Confirm only after processing. On failure the in-memory site is not advanced,
	// so the next cycle re-pulls from the old offset (may reprocess, never loses).
	if maxID > 0 {
		if err := p.config.SaveOffset(ctx, p.botID, maxID); err != nil {
			slog.Error("save offset failed, not advancing in-memory site",
				"bot", p.botName, "err", err.Error())
		}
	}

	return Result{Err: nil, HadUpdates: true}
}

// EmptyPollSleep pauses 0.3s on an empty poll (interruptible).
func EmptyPollSleep(ctx context.Context) {
	t := time.NewTimer(300 * time.Millisecond)
	defer t.Stop()
	select {
	case <-ctx.Done():
	case <-t.C:
	}
}
