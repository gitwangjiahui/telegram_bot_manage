// Package start handles /start (StartCommand.php).
package start

import (
	"context"
	"log/slog"
	"strings"

	"github.com/jh/telegram-bots/botd/internal/captcha/mathgen"
	"github.com/jh/telegram-bots/botd/internal/handlers/services"
	"github.com/jh/telegram-bots/botd/internal/tg/types"
)

const caption = "👋 欢迎使用！\n\n🤖 请计算图片中的数学题\n直接回复答案即可"

// Handle processes a /start message. fromSuper must be precomputed.
// A super admin /start yields no response.
func Handle(ctx context.Context, b *services.Bundle, m *types.Message, fromSuper bool) {
	userID := m.From.ID

	if fromSuper {
		// Super admin: empty response.
		return
	}

	// Clear old codes first (always, even for already-verified users).
	if err := b.Verify.Clear(ctx, userID); err != nil {
		slog.Warn("clear old verification code failed", "bot", b.BotName, "err", err.Error())
	}

	// Prefer a pre-generated pool entry.
	item, err := b.CaptchaP.Acquire(ctx)
	if err != nil {
		slog.Warn("captcha pool acquire failed, falling back to live render",
			"bot", b.BotName, "err", err.Error())
		item = nil
	}

	if item != nil {
		// DECISIONS #8: if save fails do NOT send the question; tell user to retry.
		if err := b.Verify.Save(ctx, userID, item.Code, item.Answer); err != nil {
			slog.Error("verification save failed, not sending pool question",
				"bot", b.BotName, "err", err.Error())
			sendBusy(ctx, b, userID)
			return
		}
		if _, err := b.Client.SendPhotoFileID(ctx, m.Chat.ID, item.FileID, caption); err != nil {
			// DECISIONS #9: wrong file_id / 400 -> degrade to live generation.
			if isWrongFileID(err) {
				slog.Warn("wrong file_id, degrading to live render", "bot", b.BotName)
				liveFallback(ctx, b, m)
				return
			}
			slog.Error("sendPhoto file_id failed", "bot", b.BotName, "err", err.Error())
		}
		return
	}

	// Pool empty: live generation fallback.
	slog.Info("captcha pool empty, live rendering", "bot", b.BotName, "component", "POOL")
	liveFallback(ctx, b, m)
}

func liveFallback(ctx context.Context, b *services.Bundle, m *types.Message) {
	q := mathgen.New()

	// DECISIONS #8: save failure -> no question, prompt retry.
	if err := b.Verify.Save(ctx, m.From.ID, q.Question, q.Answer); err != nil {
		slog.Error("verification save failed during live fallback",
			"bot", b.BotName, "err", err.Error())
		sendBusy(ctx, b, m.From.ID)
		return
	}

	png, err := b.Renderer.Render(q.Question)
	if err != nil {
		slog.Error("captcha render failed", "bot", b.BotName, "err", err.Error())
		sendBusy(ctx, b, m.From.ID)
		return
	}
	if _, _, err := b.Client.SendPhotoUpload(ctx, m.Chat.ID, png, caption); err != nil {
		slog.Error("sendPhoto upload failed", "bot", b.BotName, "err", err.Error())
	}
}

func sendBusy(ctx context.Context, b *services.Bundle, userID int64) {
	if _, err := b.Client.SendMessage(ctx, userID, "系统繁忙，请稍后重试", ""); err != nil {
		slog.Error("send busy message failed", "bot", b.BotName, "err", err.Error())
	}
}

// isWrongFileID reports whether the send failure is a 400 wrong file_id error.
func isWrongFileID(err error) bool {
	if err == nil {
		return false
	}
	msg := err.Error()
	return strings.Contains(msg, "wrong file_id") || strings.Contains(msg, " 400") ||
		strings.Contains(msg, "error 400")
}
