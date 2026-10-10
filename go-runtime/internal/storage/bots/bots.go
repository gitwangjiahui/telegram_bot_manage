// Package bots queries the bots, admins and bot_admin_rela tables.
package bots

import (
	"context"
	"database/sql"
)

// Config is the fully-resolved configuration for a single active bot.
type Config struct {
	ID           int
	BotName      string
	APIKey       string
	BotUsername  string
	IsActive     bool
	SuperAdminID int64 // 0 if none
	AdminIDs     []int64
}

// Repo accesses bot data.
type Repo struct {
	db *sql.DB
}

// New creates a bots repo.
func New(db *sql.DB) *Repo {
	return &Repo{db: db}
}

// GetActive loads an active bot and its admins.
// Returns nil without error if the bot does not exist or is inactive.
func (r *Repo) GetActive(ctx context.Context, botName string) (*Config, error) {
	const q = `
		SELECT b.id, b.bot_name, b.api_key, b.bot_username, b.is_active,
		       GROUP_CONCAT(CONCAT(r.admin_id, ':', r.admin_type)) AS admins
		  FROM bots b
		  LEFT JOIN bot_admin_rela r ON r.bot_id = b.id
		 WHERE b.bot_name = ? AND b.is_active = 1
		 GROUP BY b.id`

	var (
		id          int
		name        string
		apiKey      string
		botUsername sql.NullString
		isActive    int
		adminsRaw   sql.NullString
	)
	err := r.db.QueryRowContext(ctx, q, botName).Scan(
		&id, &name, &apiKey, &botUsername, &isActive, &adminsRaw)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}

	cfg := &Config{
		ID:          id,
		BotName:     name,
		APIKey:      apiKey,
		BotUsername: botUsername.String,
		IsActive:    isActive == 1,
	}
	cfg.SuperAdminID, cfg.AdminIDs = parseAdmins(adminsRaw.String)
	return cfg, nil
}

// ActiveName is a bot name with its active flag.
type ActiveName struct {
	BotName  string
	IsActive bool
}

// ListAll returns every bot name and active flag.
func (r *Repo) ListAll(ctx context.Context) ([]ActiveName, error) {
	rows, err := r.db.QueryContext(ctx,
		"SELECT bot_name, is_active FROM bots ORDER BY bot_name")
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []ActiveName
	for rows.Next() {
		var name string
		var active int
		if err := rows.Scan(&name, &active); err != nil {
			return nil, err
		}
		out = append(out, ActiveName{BotName: name, IsActive: active == 1})
	}
	return out, rows.Err()
}
