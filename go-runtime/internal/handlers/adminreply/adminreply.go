// Package adminreply handles an admin replying to a (forwarded) message and
// the normal-admin -> super-admin cc sync (GenericmessageCommand handleAdminReply).
package adminreply

import (
	"context"
	"log/slog"
	"regexp"

	"github.com/jh/telegram-bots/botd/internal/handlers/services"
	"github.com/jh/telegram-bots/botd/internal/tg/types"
	"github.com/jh/telegram-bots/botd/internal/ws/events"
)

var syncRe = regexp.MustCompile(`^\[转发自管理员 (\d+)\]`)

// Handle processes an admin reply. isSuper indicates the sender is the super admin.
// Returns true if the message was handled as an admin reply.
func Handle(ctx context.Context, b *services.Bundle, m *types.Message, isSuper bool,
	pub events.Publisher) bool {
	if m.ReplyToMessage == nil {
		return false
	}
	replyTo := m.ReplyToMessage

	// (A) Super admin replying to a normal-admin sync message -> that normal admin.
	if isSuper {
		if syncID := parseSync(replyTo); syncID > 0 {
			sendReplyToUser(ctx, b, m, syncID)
			return true
		}
	}

	// (B) Resolve the originating user.
	var target int64
	if replyTo.ForwardFrom != nil {
		target = replyTo.ForwardFrom.ID
	} else if uid, ok := b.Map.GetUserId(ctx, b.BotName, replyTo.MessageID); ok {
		target = uid
	}
	if target <= 0 {
		// No mapping: silent (empty response).
		return true
	}

	sendReplyToUser(ctx, b, m, target)

	// (C) Normal admin -> cc super admin.
	if !isSuper && b.SuperID > 0 {
		forwardReplyToSuper(ctx, b, m, target)
	}

	if pub != nil {
		pub.Publish(events.New(events.MessageOut, map[string]any{
			"bot_name":       b.BotName,
			"user_id":        m.From.ID,
			"target_user_id": target,
			"message_id":     m.MessageID,
		}))
	}
	return true
}

func parseSync(replyTo *types.Message) int64 {
	text := replyTo.Text
	if text == "" {
		text = replyTo.Caption
	}
	if m := syncRe.FindStringSubmatch(text); m != nil {
		return parseInt(m[1])
	}
	return 0
}

// sendReplyToUser supports text / photo only; archives the outbound message.
func sendReplyToUser(ctx context.Context, b *services.Bundle, m *types.Message, target int64) {
	if len(m.Photo) > 0 {
		fileID := m.Photo[len(m.Photo)-1].FileID
		if _, err := b.Client.SendPhotoFileID(ctx, target, fileID, m.Caption); err != nil {
			slog.Error("admin reply sendPhoto failed", "bot", b.BotName, "err", err.Error())
		}
	} else if m.Text != "" {
		if _, err := b.Client.SendMessage(ctx, target, m.Text, ""); err != nil {
			slog.Error("admin reply sendMessage failed", "bot", b.BotName, "err", err.Error())
		}
	} else {
		// Other types: nothing sent (PHP behavior).
		return
	}

	// Archive outbound (silent on failure).
	b.Log.SafeRecord(ctx, b.BotID, b.BotName, m.MessageID, "out", m.From.ID, target, m)
}

func forwardReplyToSuper(ctx context.Context, b *services.Bundle, m *types.Message, target int64) {
	adminID := m.From.ID
	name := m.From.FirstName
	if name == "" {
		name = "管理员" + formatID(adminID)
	}
	header := "[转发自管理员 " + formatID(adminID) + "] 回复了用户 " + formatID(target) + "\n" +
		"管理员: " + name + "\n─────────────\n\n"

	if m.Text != "" {
		if _, err := b.Client.SendMessage(ctx, b.SuperID, header+m.Text, ""); err != nil {
			slog.Error("cc super sendMessage failed", "bot", b.BotName, "err", err.Error())
		}
	} else if len(m.Photo) > 0 {
		fileID := m.Photo[len(m.Photo)-1].FileID
		if _, err := b.Client.SendPhotoFileID(ctx, b.SuperID, fileID, header+m.Caption); err != nil {
			slog.Error("cc super sendPhoto failed", "bot", b.BotName, "err", err.Error())
		}
	}
}
