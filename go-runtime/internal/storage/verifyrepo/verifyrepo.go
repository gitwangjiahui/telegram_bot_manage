// Package verifyrepo accesses verification_codes and user_verification.
package verifyrepo

import (
	"context"
	"database/sql"
)

// Repo accesses verification tables for a single bot.
type Repo struct {
	db      *sql.DB
	botName string
}

// New creates a verification repo for the given bot.
func New(db *sql.DB, botName string) *Repo {
	return &Repo{db: db, botName: botName}
}

// Clear deletes outstanding codes for a user (called before Save on /start).
func (r *Repo) Clear(ctx context.Context, userID int64) error {
	_, err := r.db.ExecContext(ctx,
		"DELETE FROM verification_codes WHERE bot_name = ? AND user_id = ?",
		r.botName, userID)
	return err
}

// Save inserts a new code expiring 5 minutes from now. Clear must be called first.
func (r *Repo) Save(ctx context.Context, userID int64, code, answer string) error {
	_, err := r.db.ExecContext(ctx,
		`INSERT INTO verification_codes (bot_name, user_id, code, answer, expires_at)
		 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))`,
		r.botName, userID, code, answer)
	return err
}

// Verify checks a non-expired exact-match code, deleting it on success.
func (r *Repo) Verify(ctx context.Context, userID int64, answer string) (bool, error) {
	tx, err := r.db.BeginTx(ctx, nil)
	if err != nil {
		return false, err
	}
	defer tx.Rollback() //nolint:errcheck // no-op after commit

	var id int64
	err = tx.QueryRowContext(ctx,
		`SELECT id FROM verification_codes
		  WHERE bot_name = ? AND user_id = ? AND answer = ? AND expires_at > NOW()
		  ORDER BY id DESC LIMIT 1 FOR UPDATE`,
		r.botName, userID, answer).Scan(&id)
	if err == sql.ErrNoRows {
		if rbErr := tx.Rollback(); rbErr != nil {
			return false, rbErr
		}
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if _, err := tx.ExecContext(ctx,
		"DELETE FROM verification_codes WHERE id = ?", id); err != nil {
		return false, err
	}
	if err := tx.Commit(); err != nil {
		return false, err
	}
	return true, nil
}

// MarkVerified upserts a verified user.
func (r *Repo) MarkVerified(ctx context.Context, userID int64) error {
	_, err := r.db.ExecContext(ctx,
		`INSERT INTO user_verification (bot_name, user_id, verified_at)
		 VALUES (?, ?, NOW())
		 ON DUPLICATE KEY UPDATE verified_at = NOW()`,
		r.botName, userID)
	return err
}

// IsVerified reports whether a user has a verification row.
func (r *Repo) IsVerified(ctx context.Context, userID int64) (bool, error) {
	var id int64
	err := r.db.QueryRowContext(ctx,
		"SELECT id FROM user_verification WHERE bot_name = ? AND user_id = ? LIMIT 1",
		r.botName, userID).Scan(&id)
	if err == sql.ErrNoRows {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return true, nil
}

// Remove deletes a user's verification (on bot kicked).
func (r *Repo) Remove(ctx context.Context, userID int64) error {
	_, err := r.db.ExecContext(ctx,
		"DELETE FROM user_verification WHERE bot_name = ? AND user_id = ?",
		r.botName, userID)
	return err
}
