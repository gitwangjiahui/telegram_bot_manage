// Package longmanrepo performs the minimal Longman historical-table writes
// required to keep Node message/forward queries working (DECISIONS #11):
// only `user` and `message`. chat/user_chat/telegram_update are NOT written.
package longmanrepo

import (
	"context"
	"database/sql"
	"encoding/json"

	"github.com/jh/telegram-bots/botd/internal/tg/types"
)

// Repo writes user and message rows.
type Repo struct {
	db *sql.DB
}

// New creates a Longman repo.
func New(db *sql.DB) *Repo {
	return &Repo{db: db}
}

// UpsertUser inserts/updates a user profile.
func (r *Repo) UpsertUser(ctx context.Context, u *types.User) error {
	if u == nil {
		return nil
	}
	const q = `
		INSERT INTO user (id, is_bot, username, first_name, last_name, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, NOW(), NOW())
		ON DUPLICATE KEY UPDATE
			is_bot = VALUES(is_bot),
			username = VALUES(username),
			first_name = VALUES(first_name),
			last_name = VALUES(last_name),
			updated_at = NOW()`
	_, err := r.db.ExecContext(ctx, q,
		u.ID, boolTiny(u.IsBot), nullable(u.Username), u.FirstName, nullable(u.LastName))
	return err
}

// SaveMessage inserts one row per incoming private message.
// The deployed Longman `message` table uses PK (chat_id, id) where id carries
// the TG message id (see vendor structure.sql), so the row is keyed directly.
// replyToID is the local id of the replied-to message (nil when absent/not in DB).
func (r *Repo) SaveMessage(ctx context.Context, m *types.Message, replyToID *int64) error {
	if m == nil {
		return nil
	}
	var senderID int64
	if m.From != nil {
		senderID = m.From.ID
	}

	_, err := r.db.ExecContext(ctx, `
		INSERT INTO message
			(chat_id, id, user_id, date, text, reply_to_message,
			 photo, caption, sticker, voice, video, document, animation,
			 audio, video_note, contact, location, entities, caption_entities,
			 created_at, updated_at)
		VALUES
			(?, ?, ?, FROM_UNIXTIME(?), ?, ?,
			 ?, ?, ?, ?, ?, ?, ?,
			 ?, ?, ?, ?, ?, ?,
			 NOW(), NOW())
		ON DUPLICATE KEY UPDATE
			user_id = VALUES(user_id),
			text = VALUES(text),
			reply_to_message = VALUES(reply_to_message),
			photo = VALUES(photo),
			caption = VALUES(caption),
			sticker = VALUES(sticker),
			voice = VALUES(voice),
			video = VALUES(video),
			document = VALUES(document),
			animation = VALUES(animation),
			audio = VALUES(audio),
			video_note = VALUES(video_note),
			contact = VALUES(contact),
			location = VALUES(location),
			entities = VALUES(entities),
			caption_entities = VALUES(caption_entities),
			updated_at = NOW()`,
		m.Chat.ID, m.MessageID, senderID, m.Date, nullable(m.Text), replyToID,
		jsonCol(photoJSON(m)), nullable(m.Caption),
		jsonCol(objJSON(m.Sticker)), jsonCol(objJSON(m.Voice)),
		jsonCol(objJSON(m.Video)), jsonCol(objJSON(m.Document)),
		jsonCol(objJSON(m.Animation)), jsonCol(objJSON(m.Audio)),
		jsonCol(objJSON(m.VideoNote)), jsonCol(objJSON(m.Contact)),
		jsonCol(objJSON(m.Location)),
		jsonCol(entitiesJSON(m.Entities)), jsonCol(entitiesJSON(m.CaptionEntities)))
	return err
}

func photoJSON(m *types.Message) any {
	if len(m.Photo) == 0 {
		return nil
	}
	// Largest size: media columns store the array; Node reads index/largest.
	return m.Photo
}

func entitiesJSON(e []types.MessageEntity) any {
	if len(e) == 0 {
		return nil
	}
	return e
}

func objJSON(v any) any {
	// Distinguish nil pointer from a value to encode.
	switch val := v.(type) {
	case *types.Sticker:
		if val == nil {
			return nil
		}
		return val
	case *types.Voice:
		if val == nil {
			return nil
		}
		return val
	case *types.Video:
		if val == nil {
			return nil
		}
		return val
	case *types.Document:
		if val == nil {
			return nil
		}
		return val
	case *types.Animation:
		if val == nil {
			return nil
		}
		return val
	case *types.Audio:
		if val == nil {
			return nil
		}
		return val
	case *types.VideoNote:
		if val == nil {
			return nil
		}
		return val
	case *types.Contact:
		if val == nil {
			return nil
		}
		return val
	case *types.Location:
		if val == nil {
			return nil
		}
		return val
	}
	return nil
}

// jsonCol returns a nullable JSON string for a column.
func jsonCol(v any) any {
	if v == nil {
		return nil
	}
	b, err := json.Marshal(v)
	if err != nil {
		return nil
	}
	return string(b)
}

func nullable(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func boolTiny(b bool) int {
	if b {
		return 1
	}
	return 0
}

// FindMessageID resolves the local `message.id` used as reply_to_message.
// In the deployed Longman schema the PK is (chat_id, id) where id carries the
// TG message id, so a same-private-chat reply maps directly; admin-side
// forwarded messages live in another chat and return NULL (per DECISIONS #11).
func (r *Repo) FindMessageID(ctx context.Context, chatID, tgMsgID int64) (*int64, bool) {
	var id int64
	err := r.db.QueryRowContext(ctx,
		"SELECT id FROM message WHERE chat_id = ? AND id = ? LIMIT 1",
		chatID, tgMsgID).Scan(&id)
	if err == sql.ErrNoRows {
		return nil, false
	}
	if err != nil {
		return nil, false
	}
	return &id, true
}
