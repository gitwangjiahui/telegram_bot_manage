// Package maprepo reads and writes forward_map.
package maprepo

import (
	"context"
	"database/sql"
)

// Repo accesses forward_map.
type Repo struct {
	db *sql.DB
}

// New creates a forward_map repo.
func New(db *sql.DB) *Repo {
	return &Repo{db: db}
}

// Save records a successful forward. Upsert on (bot_name, forwarded_msg_id).
// Failures are surfaced to the caller; callers in the hot path log and ignore.
func (r *Repo) Save(ctx context.Context, botName string, forwardedMsgID, originalMsgID, userID int64) error {
	const q = `
		INSERT INTO forward_map (bot_name, forwarded_msg_id, original_msg_id, user_id)
		VALUES (?, ?, ?, ?)
		ON DUPLICATE KEY UPDATE
			original_msg_id = VALUES(original_msg_id),
			user_id = VALUES(user_id)`
	_, err := r.db.ExecContext(ctx, q, botName, forwardedMsgID, originalMsgID, userID)
	return err
}

// GetUserId resolves the originating user for an admin-side message id.
// Returns (0,false) when not found.
func (r *Repo) GetUserId(ctx context.Context, botName string, forwardedMsgID int64) (int64, bool) {
	const q = `SELECT user_id FROM forward_map WHERE bot_name = ? AND forwarded_msg_id = ? LIMIT 1`
	var userID int64
	err := r.db.QueryRowContext(ctx, q, botName, forwardedMsgID).Scan(&userID)
	if err == sql.ErrNoRows {
		return 0, false
	}
	if err != nil {
		return 0, false
	}
	return userID, true
}
