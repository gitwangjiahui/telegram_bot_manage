// Package chatmember handles my_chat_member updates (MychatmemberCommand.php).
package chatmember

import (
	"context"
	"log/slog"

	"github.com/jh/telegram-bots/botd/internal/handlers/services"
	"github.com/jh/telegram-bots/botd/internal/tg/types"
)

// Handle processes a my_chat_member update. onKicked is used to clear the
// runner's in-memory verification cache.
func Handle(ctx context.Context, b *services.Bundle, upd *types.ChatMemberUpdated,
	onKicked func(userID int64)) {
	if upd == nil {
		return
	}
	if upd.NewChatMember.Status != "kicked" {
		return
	}
	userID := upd.From.ID
	if err := b.Verify.Remove(ctx, userID); err != nil {
		slog.Warn("remove verification on kicked failed", "bot", b.BotName, "err", err.Error())
	}
	if onKicked != nil {
		onKicked(userID)
	}
}
