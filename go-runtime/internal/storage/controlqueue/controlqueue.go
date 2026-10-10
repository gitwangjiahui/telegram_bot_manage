// Package controlqueue accesses the bot_control table.
package controlqueue

import (
	"context"
	"database/sql"
)

// Action is a control action.
type Action string

const (
	ActionStart   Action = "start"
	ActionStop    Action = "stop"
	ActionRestart Action = "restart"
)

// Command is a claimed control command.
type Command struct {
	ID      int64
	BotID   int
	Action  Action
	BotName string
}

// Repo accesses bot_control.
type Repo struct {
	db *sql.DB
}

// New creates a control queue repo.
func New(db *sql.DB) *Repo {
	return &Repo{db: db}
}

// RecoverRunning resets stale running commands back to pending at startup.
func (r *Repo) RecoverRunning(ctx context.Context) error {
	_, err := r.db.ExecContext(ctx,
		"UPDATE bot_control SET status = 'pending', executed_at = NULL WHERE status = 'running'")
	return err
}

// NextPending returns the earliest pending command joined to its bot name.
// Returns nil without error when the queue is empty.
func (r *Repo) NextPending(ctx context.Context) (*Command, error) {
	const q = `
		SELECT c.id, c.bot_id, c.action, b.bot_name
		  FROM bot_control c
		  JOIN bots b ON b.id = c.bot_id
		 WHERE c.status = 'pending'
		 ORDER BY c.id ASC
		 LIMIT 1`
	var (
		id      int64
		botID   int
		action  string
		botName string
	)
	err := r.db.QueryRowContext(ctx, q).Scan(&id, &botID, &action, &botName)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &Command{ID: id, BotID: botID, Action: Action(action), BotName: botName}, nil
}

// Claim atomically marks a pending command running. Returns false if it was
// already claimed.
func (r *Repo) Claim(ctx context.Context, id int64) (bool, error) {
	res, err := r.db.ExecContext(ctx,
		"UPDATE bot_control SET status = 'running', executed_at = NOW() WHERE id = ? AND status = 'pending'",
		id)
	if err != nil {
		return false, err
	}
	n, err := res.RowsAffected()
	if err != nil {
		return false, err
	}
	return n == 1, nil
}

// Finish writes the terminal status and result. result is truncated to 240 chars.
func (r *Repo) Finish(ctx context.Context, id int64, status, result string) error {
	if len(result) > 240 {
		result = result[:237] + "..."
	}
	if result == "" {
		result = "ok"
	}
	_, err := r.db.ExecContext(ctx,
		"UPDATE bot_control SET status = ?, result = ?, executed_at = NOW() WHERE id = ?",
		status, result, id)
	return err
}
