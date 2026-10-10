// Package logrepo writes and aggregates message_log.
package logrepo

import (
	"context"
	"database/sql"

	"github.com/jh/telegram-bots/botd/internal/tg/types"
)

// Repo accesses message_log.
type Repo struct {
	db *sql.DB
}

// New creates a message_log repo.
func New(db *sql.DB) *Repo {
	return &Repo{db: db}
}

// DetectType mirrors PHP MessageLog detectType priority.
// photo > voice > video > video_note > document > sticker > animation > audio
// > contact > location > text > other.
func DetectType(m *types.Message) string {
	switch {
	case len(m.Photo) > 0:
		return "photo"
	case m.Voice != nil:
		return "voice"
	case m.Video != nil:
		return "video"
	case m.VideoNote != nil:
		return "video_note"
	case m.Document != nil:
		return "document"
	case m.Sticker != nil:
		return "sticker"
	case m.Animation != nil:
		return "animation"
	case m.Audio != nil:
		return "audio"
	case m.Contact != nil:
		return "contact"
	case m.Location != nil:
		return "location"
	case m.Text != "":
		return "text"
	default:
		return "other"
	}
}

// SafeRecord archives a message. Any failure is swallowed (never affects send/receive).
// Uses the unique key (bot_name, message_id, direction).
func (r *Repo) SafeRecord(ctx context.Context, botID int, botName string,
	messageID int64, direction string, senderID, userID int64, m *types.Message) {
	msgType := DetectType(m)
	text := m.Text
	if text == "" {
		text = m.Caption
	}
	if len(text) > 5000 {
		text = text[:5000]
	}

	const q = `
		INSERT INTO message_log
			(bot_id, bot_name, user_id, message_id, direction, sender_id, msg_type, text_content)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?)
		ON DUPLICATE KEY UPDATE text_content = VALUES(text_content)`
	// Errors intentionally ignored.
	_, _ = r.db.ExecContext(ctx, q,
		botID, botName, userID, messageID, direction, senderID, msgType, text)
}

// TodayCounts returns (in, out) counts for the current day.
func (r *Repo) TodayCounts(ctx context.Context, botID int) (in, out int) {
	const q = `
		SELECT COALESCE(SUM(direction = 'in'), 0), COALESCE(SUM(direction = 'out'), 0)
		  FROM message_log
		 WHERE bot_id = ? AND created_at >= CURDATE()`
	if err := r.db.QueryRowContext(ctx, q, botID).Scan(&in, &out); err != nil {
		return 0, 0
	}
	return in, out
}
