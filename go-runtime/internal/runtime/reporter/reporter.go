// Package reporter upserts bot_heartbeat (docs 2.6).
package reporter

import (
	"context"
	"database/sql"
	"os"
	"time"

	"github.com/jh/telegram-bots/botd/internal/config/appconfig"
	"github.com/jh/telegram-bots/botd/internal/storage/captchapool"
	"github.com/jh/telegram-bots/botd/internal/storage/logrepo"
)

// Reporter writes heartbeats: each loop iteration plus a 10s ticker fallback.
type Reporter struct {
	botName   string
	botID     int
	startedAt string
	db        *sql.DB
	config    *appconfig.Cache
	pool      *captchapool.Repo
	logs      *logrepo.Repo

	// shared mutable error state guarded by the runner.
	state *State
}

// State is the heartbeat error state shared with the runner.
type State struct {
	LastError   string
	LastErrorAt string
}

// New creates a Reporter.
func New(botName string, botID int, startedAt string, db *sql.DB,
	cfg *appconfig.Cache, pool *captchapool.Repo, logs *logrepo.Repo, state *State) *Reporter {
	return &Reporter{
		botName:   botName,
		botID:     botID,
		startedAt: startedAt,
		db:        db,
		config:    cfg,
		pool:      pool,
		logs:      logs,
		state:     state,
	}
}

// Touch aggregates counters (each independently defaulting to 0) and upserts.
// Heartbeat write failures are silent. status is "polling" or "stopped".
func (r *Reporter) Touch(ctx context.Context, status string) {
	captchaAvailable := 0
	if n, err := r.pool.CountAvailable(ctx); err == nil {
		captchaAvailable = n
	}

	todayIn, todayOut := r.logs.TodayCounts(ctx, r.botID)

	offset := int64(0)
	if v, ok := r.config.GetOffset(ctx, r.botID); ok {
		offset = v
	}

	var lastError any
	var lastErrorAt any
	if r.state.LastError != "" {
		lastError = trunc500(r.state.LastError)
		lastErrorAt = r.state.LastErrorAt
	}

	const q = `
		INSERT INTO bot_heartbeat
			(bot_name, pid, started_at, heartbeat_at, last_update_id, status,
			 today_in, today_out, captcha_available, last_error, last_error_at)
		VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
		ON DUPLICATE KEY UPDATE
			pid = VALUES(pid),
			started_at = VALUES(started_at),
			heartbeat_at = NOW(),
			last_update_id = VALUES(last_update_id),
			status = VALUES(status),
			today_in = VALUES(today_in),
			today_out = VALUES(today_out),
			captcha_available = VALUES(captcha_available),
			last_error = VALUES(last_error),
			last_error_at = VALUES(last_error_at)`
	// Errors intentionally ignored (Heartbeat::touch).
	_, _ = r.db.ExecContext(ctx, q,
		r.botName, os.Getpid(), parseTime(r.startedAt), offset, status,
		todayIn, todayOut, captchaAvailable, lastError, lastErrorAt)
}

// MarkStopped writes one final stopped heartbeat.
func (r *Reporter) MarkStopped(ctx context.Context) {
	_, _ = r.db.ExecContext(ctx,
		"UPDATE bot_heartbeat SET status = 'stopped', heartbeat_at = NOW() WHERE bot_name = ?",
		r.botName)
}

// SetError records an error and timestamp on the shared state.
func (r *Reporter) SetError(msg string) {
	r.state.LastError = msg
	r.state.LastErrorAt = time.Now().Format("2006-01-02 15:04:05")
}

// ClearError clears last_error after consecutive successes (DECISIONS #3).
func (r *Reporter) ClearError() {
	r.state.LastError = ""
	r.state.LastErrorAt = ""
}

// RunTicker emits a polling heartbeat at least every 10s while alive.
func (r *Reporter) RunTicker(ctx context.Context) {
	t := time.NewTicker(10 * time.Second)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			r.Touch(ctx, "polling")
		}
	}
}

func trunc500(s string) string {
	if len(s) > 500 {
		return s[:500]
	}
	return s
}

func parseTime(s string) any {
	if s == "" {
		return nil
	}
	if t, err := time.ParseInLocation("2006-01-02 15:04:05", s, time.Local); err == nil {
		return t
	}
	return nil
}
