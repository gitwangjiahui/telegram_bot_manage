package hub

import (
	"testing"

	"github.com/jh/telegram-bots/botd/internal/ws/events"
)

func TestShouldSendStatus(t *testing.T) {
	h := New()
	e := events.New(events.BotStatus, map[string]any{"bot_name": "bot1", "status": "polling"})
	if !h.shouldSendStatus(e) {
		t.Fatal("first status should be sent")
	}
	if h.shouldSendStatus(e) {
		t.Error("second status within window should be throttled")
	}
	e2 := events.New(events.BotStatus, map[string]any{"bot_name": "bot2", "status": "polling"})
	if !h.shouldSendStatus(e2) {
		t.Error("different bot status should be sent")
	}
}
