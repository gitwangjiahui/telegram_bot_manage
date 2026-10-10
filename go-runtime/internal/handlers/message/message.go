// Package message handles ordinary verified-user messages: auto reply,
// archive, and forward fan-out to admins.
package message

import (
	"context"
	"log/slog"

	"github.com/jh/telegram-bots/botd/internal/handlers/services"
	"github.com/jh/telegram-bots/botd/internal/tg/fanout"
	"github.com/jh/telegram-bots/botd/internal/tg/types"
	"github.com/jh/telegram-bots/botd/internal/ws/events"
)

// Handle processes a verified user's private message.
func Handle(ctx context.Context, b *services.Bundle, m *types.Message, pub events.Publisher) {
	userID := m.From.ID

	// 1) Auto reply first. DECISIONS #8: auto-reply failure must not block forwarding.
	autoReply := b.Config.GetAutoReply(ctx, b.BotID)
	if autoReply != "" {
		if _, err := b.Client.SendMessage(ctx, userID, autoReply, ""); err != nil {
			slog.Warn("auto reply send failed, continuing with forward",
				"bot", b.BotName, "err", err.Error())
		}
	}

	// 2) Minimal Longman writes (user + message). Failure never blocks.
	safeLongman(ctx, b, m)

	// 3) Archive inbound (silent on failure).
	b.Log.SafeRecord(ctx, b.BotID, b.BotName, m.MessageID, "in", userID, userID, m)

	// 4) Forward to all admins concurrently.
	targets := b.AdminTargets()
	forwarded := fanout.ForwardToChats(ctx, b.Client, targets, m.Chat.ID, m.MessageID)
	for adminID, fwdMsgID := range forwarded {
		if err := b.Map.Save(ctx, b.BotName, fwdMsgID, m.MessageID, userID); err != nil {
			slog.Warn("forward_map save failed", "bot", b.BotName, "admin", adminID,
				"err", err.Error())
		}
	}

	if pub != nil {
		pub.Publish(events.New(events.MessageNew, map[string]any{
			"bot_name":   b.BotName,
			"user_id":    userID,
			"message_id": m.MessageID,
			"msg_type":   typeOf(m),
			"sender_id":  userID,
		}))
	}
}

func safeLongman(ctx context.Context, b *services.Bundle, m *types.Message) {
	if m.From != nil {
		if err := b.Longman.UpsertUser(ctx, m.From); err != nil {
			slog.Warn("longman upsert user failed", "bot", b.BotName, "err", err.Error())
		}
	}

	// Resolve reply_to_message local id within this private chat, else NULL.
	var replyToID *int64
	if m.ReplyToMessage != nil {
		if id, ok := b.Longman.FindMessageID(ctx, m.Chat.ID, m.ReplyToMessage.MessageID); ok {
			replyToID = id
		}
	}
	if err := b.Longman.SaveMessage(ctx, m, replyToID); err != nil {
		slog.Warn("longman save message failed", "bot", b.BotName, "err", err.Error())
	}
}
