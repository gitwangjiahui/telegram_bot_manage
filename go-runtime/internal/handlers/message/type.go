package message

import (
	"github.com/jh/telegram-bots/botd/internal/storage/logrepo"
	"github.com/jh/telegram-bots/botd/internal/tg/types"
)

func typeOf(m *types.Message) string {
	return logrepo.DetectType(m)
}
