// Package verification handles captcha answer checking and verification.
package verification

import (
	"context"
	"log/slog"
	"time"

	"github.com/jh/telegram-bots/botd/internal/handlers/services"
	"github.com/jh/telegram-bots/botd/internal/tg/fanout"
	"github.com/jh/telegram-bots/botd/internal/tg/types"
	"github.com/jh/telegram-bots/botd/internal/ws/events"
)

// Check validates a numeric answer and drives the verification flow.
// vcacheSet is called to update the runner's in-memory verification cache.
func Check(ctx context.Context, b *services.Bundle, m *types.Message, answer string,
	pub events.Publisher, vcacheSet func(bool)) {
	chatID := m.Chat.ID

	ok, err := b.Verify.Verify(ctx, m.From.ID, answer)
	if err != nil {
		slog.Error("verify query failed", "bot", b.BotName, "err", err.Error())
		return
	}

	if !ok {
		if _, err := b.Client.SendMessage(ctx, chatID,
			"❌ 答案错误或已过期\n请发送 /start 重新获取验证码", ""); err != nil {
			slog.Error("send wrong-answer message failed", "bot", b.BotName, "err", err.Error())
		}
		return
	}

	if err := b.Verify.MarkVerified(ctx, m.From.ID); err != nil {
		// Best effort: still notify success; user may be re-checked next time.
		slog.Error("mark verified failed", "bot", b.BotName, "err", err.Error())
	}
	vcacheSet(true)

	// Reply to the user first, then notify admins.
	if _, err := b.Client.SendMessage(ctx, chatID,
		"✅ 验证成功！\n\n欢迎使用，现在可以正常使用了。", ""); err != nil {
		slog.Error("send verify-success message failed", "bot", b.BotName, "err", err.Error())
	}

	notifyAdmins(ctx, b, m)

	if pub != nil {
		pub.Publish(events.New(events.UserVerified, map[string]any{
			"bot_name": b.BotName,
			"user_id":  m.From.ID,
		}))
	}
}

func notifyAdmins(ctx context.Context, b *services.Bundle, m *types.Message) {
	u := m.From
	username := "无"
	if u.Username != "" {
		username = "@" + u.Username
	}
	fullName := "无"
	if name := joinName(u.FirstName, u.LastName); name != "" {
		fullName = name
	}

	text := "✅ 新用户验证通过\n\n👤 用户信息\n" +
		"├ ID: <code>" + formatID(u.ID) + "</code>\n" +
		"├ 用户名: " + username + "\n" +
		"├ 姓名: " + fullName + "\n" +
		"└ 时间: " + time.Now().Format("2006-01-02 15:04:05") + "\n\n" +
		"💡 可直接回复此用户的消息"

	targets := b.AdminTargets()
	fanout.SendToChats(ctx, b.Client, targets, text, "HTML")
}
