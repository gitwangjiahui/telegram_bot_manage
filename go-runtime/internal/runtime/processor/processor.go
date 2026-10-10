// Package processor dispatches updates to handlers per docs 3.3 decision tree.
package processor

import (
	"context"
	"log/slog"
	"strings"

	"github.com/jh/telegram-bots/botd/internal/handlers/adminreply"
	"github.com/jh/telegram-bots/botd/internal/handlers/chatmember"
	"github.com/jh/telegram-bots/botd/internal/handlers/message"
	"github.com/jh/telegram-bots/botd/internal/handlers/services"
	"github.com/jh/telegram-bots/botd/internal/handlers/start"
	"github.com/jh/telegram-bots/botd/internal/handlers/verification"
	"github.com/jh/telegram-bots/botd/internal/runtime/vcache"
	"github.com/jh/telegram-bots/botd/internal/tg/types"
	"github.com/jh/telegram-bots/botd/internal/ws/events"
)

// Processor dispatches one bot's updates.
type Processor struct {
	bundle *services.Bundle
	cache  *vcache.Cache
	pub    events.Publisher
}

// New creates a Processor.
func New(b *services.Bundle, cache *vcache.Cache, pub events.Publisher) *Processor {
	return &Processor{bundle: b, cache: cache, pub: pub}
}

func (p *Processor) isAdmin(id int64) bool {
	if id == p.bundle.SuperID {
		return true
	}
	for _, a := range p.bundle.AdminIDs {
		if a == id {
			return true
		}
	}
	return false
}

// Process dispatches a single update. Per docs 3.2, a panic/error here is
// contained by the poller so other updates are unaffected.
func (p *Processor) Process(ctx context.Context, u *types.Update) {
	defer func() {
		if r := recover(); r != nil {
			slog.Error("panic processing update", "bot", p.bundle.BotName,
				"update_id", u.UpdateID, "err", r)
		}
	}()

	// my_chat_member first.
	if u.MyChatMember != nil {
		chatmember.Handle(ctx, p.bundle, u.MyChatMember, func(userID int64) {
			p.cache.Invalidate(p.bundle.BotName, userID)
		})
		return
	}

	m := u.Message
	if m == nil || m.From == nil {
		return
	}

	// DECISIONS #5: only private chats are processed.
	if m.Chat.Type != "private" {
		return
	}

	fromID := m.From.ID
	isSuper := fromID == p.bundle.SuperID
	isAdmin := p.isAdmin(fromID)

	// Admin reply branch: admin + reply_to_message.
	if isAdmin && m.ReplyToMessage != nil {
		if adminreply.Handle(ctx, p.bundle, m, isSuper, p.pub) {
			return
		}
	}

	// Admin message without a reply -> hint.
	if isAdmin && m.ReplyToMessage == nil {
		if _, err := p.bundle.Client.SendMessage(ctx, fromID,
			"长按对方消息选择回复，对方才能收到消息哦", ""); err != nil {
			slog.Error("send admin no-reply hint failed", "bot", p.bundle.BotName,
				"err", err.Error())
		}
		return
	}

	// /start (payload tolerated; super admin handled inside).
	if isStartCommand(m.Text) {
		start.Handle(ctx, p.bundle, m, isSuper)
		return
	}

	// Non-admin: verification gating.
	verified, cached := p.cache.Get(p.bundle.BotName, fromID)
	if !cached {
		ok, err := p.bundle.Verify.IsVerified(ctx, fromID)
		if err != nil {
			slog.Warn("isVerified query failed", "bot", p.bundle.BotName, "err", err.Error())
		}
		verified = ok
		p.cache.Set(p.bundle.BotName, fromID, verified)
	}

	if !verified {
		trimmed := strings.TrimSpace(m.Text)
		if isNumeric(trimmed) {
			verification.Check(ctx, p.bundle, m, trimmed, p.pub, func(v bool) {
				p.cache.Set(p.bundle.BotName, fromID, v)
			})
			return
		}
		if _, err := p.bundle.Client.SendMessage(ctx, fromID,
			"⚠️ 请先完成验证\n发送 /start 获取验证码", ""); err != nil {
			slog.Error("send not-verified message failed", "bot", p.bundle.BotName,
				"err", err.Error())
		}
		return
	}

	// Verified user: forward flow.
	message.Handle(ctx, p.bundle, m, p.pub)
}

func isStartCommand(text string) bool {
	text = strings.TrimSpace(text)
	if text == "/start" {
		return true
	}
	// Tolerate "/start payload".
	if strings.HasPrefix(text, "/start ") || strings.HasPrefix(text, "/start@") {
		return true
	}
	return false
}

func isNumeric(s string) bool {
	if s == "" {
		return false
	}
	for _, c := range s {
		if c < '0' || c > '9' {
			return false
		}
	}
	return true
}
