// Package captchapool accesses the captcha_pool table.
package captchapool

import (
	"context"
	"database/sql"
)

// Item is a pool entry.
type Item struct {
	FileID string
	Code   string
	Answer string
}

// Repo accesses captcha_pool for a single bot.
type Repo struct {
	db      *sql.DB
	botName string
}

// New creates a captcha pool repo.
func New(db *sql.DB, botName string) *Repo {
	return &Repo{db: db, botName: botName}
}

// CountAvailable returns the number of available entries.
func (r *Repo) CountAvailable(ctx context.Context) (int, error) {
	var n int
	err := r.db.QueryRowContext(ctx,
		"SELECT COUNT(*) FROM captcha_pool WHERE bot_name = ? AND status = 'available'",
		r.botName).Scan(&n)
	return n, err
}

// Acquire atomically takes one available entry (FOR UPDATE, marks used).
// Returns nil without error when the pool is empty.
func (r *Repo) Acquire(ctx context.Context) (*Item, error) {
	tx, err := r.db.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback() //nolint:errcheck // no-op after commit

	var (
		id     int64
		fileID string
		code   string
		answer string
	)
	err = tx.QueryRowContext(ctx,
		`SELECT id, file_id, code, answer FROM captcha_pool
		  WHERE bot_name = ? AND status = 'available'
		  ORDER BY id LIMIT 1 FOR UPDATE`,
		r.botName).Scan(&id, &fileID, &code, &answer)
	if err == sql.ErrNoRows {
		if rbErr := tx.Rollback(); rbErr != nil {
			return nil, rbErr
		}
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if _, err := tx.ExecContext(ctx,
		"UPDATE captcha_pool SET status = 'used', used_at = NOW() WHERE id = ?", id); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return &Item{FileID: fileID, Code: code, Answer: answer}, nil
}

// Inserted is a row to be persisted.
type Inserted struct {
	FileID string
	Code   string
	Answer string
}

// InsertMany bulk-inserts pool entries.
func (r *Repo) InsertMany(ctx context.Context, items []Inserted) (int, error) {
	if len(items) == 0 {
		return 0, nil
	}
	stmt, err := r.db.PrepareContext(ctx,
		`INSERT INTO captcha_pool (bot_name, file_id, code, answer) VALUES (?, ?, ?, ?)`)
	if err != nil {
		return 0, err
	}
	defer stmt.Close()

	count := 0
	for _, it := range items {
		if _, err := stmt.ExecContext(ctx, r.botName, it.FileID, it.Code, it.Answer); err != nil {
			return count, err
		}
		count++
	}
	return count, nil
}

// PruneUsed deletes used entries older than keepDays.
func (r *Repo) PruneUsed(ctx context.Context, keepDays int) (int64, error) {
	res, err := r.db.ExecContext(ctx,
		`DELETE FROM captcha_pool
		  WHERE bot_name = ? AND status = 'used'
		  AND used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL ? DAY)`,
		r.botName, keepDays)
	if err != nil {
		return 0, err
	}
	return res.RowsAffected()
}
