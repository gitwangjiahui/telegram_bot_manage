// Package statusrepo reads bot_heartbeat rows for the WS hello snapshot.
package statusrepo

import (
	"context"
	"database/sql"
)

// Repo reads heartbeats.
type Repo struct {
	db *sql.DB
}

// New creates a status repo.
func New(db *sql.DB) *Repo { return &Repo{db: db} }

// All returns every heartbeat row as a generic map keyed by bot name.
func (r *Repo) All(ctx context.Context) map[string]any {
	const q = `
		SELECT bot_name, pid, started_at, heartbeat_at, last_update_id, status,
		       today_in, today_out, captcha_available, last_error, last_error_at
		  FROM bot_heartbeat`
	rows, err := r.db.QueryContext(ctx, q)
	if err != nil {
		return map[string]any{"bots": []any{}}
	}
	defer rows.Close()

	botsList := []map[string]any{}
	for rows.Next() {
		var (
			botName   string
			pid       int
			startedAt sql.NullTime
			hbAt      sql.NullTime
			offset    int64
			status    string
			todayIn   int
			todayOut  int
			captcha   int
			lastError sql.NullString
			errAt     sql.NullTime
		)
		if err := rows.Scan(&botName, &pid, &startedAt, &hbAt, &offset, &status,
			&todayIn, &todayOut, &captcha, &lastError, &errAt); err != nil {
			continue
		}
		row := map[string]any{
			"bot_name":          botName,
			"pid":               pid,
			"last_update_id":    offset,
			"status":            status,
			"today_in":          todayIn,
			"today_out":         todayOut,
			"captcha_available": captcha,
			"age_seconds":       ageSeconds(hbAt),
		}
		if startedAt.Valid {
			row["started_at"] = startedAt.Time.Unix()
		}
		if hbAt.Valid {
			row["heartbeat_at"] = hbAt.Time.Unix()
		}
		if lastError.Valid {
			row["last_error"] = lastError.String
		}
		if errAt.Valid {
			row["last_error_at"] = errAt.Time.Unix()
		}
		botsList = append(botsList, row)
	}
	return map[string]any{"protocol": 1, "bots": botsList}
}

func ageSeconds(hbAt sql.NullTime) int64 {
	if !hbAt.Valid {
		return -1
	}
	return unixNow() - hbAt.Time.Unix()
}
