// Package fanout provides TgMulti-equivalent concurrent sends to multiple chats.
package fanout

import (
	"context"
	"sync"

	tgclient "github.com/jh/telegram-bots/botd/internal/tg/client"
)

// ForwardToChats concurrently forwards a message, returning chatID -> new message_id
// for successful targets only.
func ForwardToChats(ctx context.Context, c *tgclient.Client, chatIDs []int64, fromChatID, messageID int64) map[int64]int64 {
	var (
		mu  sync.Mutex
		out = map[int64]int64{}
		wg  sync.WaitGroup
	)
	for _, id := range chatIDs {
		wg.Add(1)
		go func(chatID int64) {
			defer wg.Done()
			mid, err := c.ForwardMessage(ctx, chatID, fromChatID, messageID)
			if err != nil {
				return
			}
			mu.Lock()
			out[chatID] = mid
			mu.Unlock()
		}(id)
	}
	wg.Wait()
	return out
}

// SendToChats concurrently sends the same text, returning the success count.
func SendToChats(ctx context.Context, c *tgclient.Client, chatIDs []int64, text, parseMode string) int {
	var (
		mu sync.Mutex
		ok int
		wg sync.WaitGroup
	)
	for _, id := range chatIDs {
		wg.Add(1)
		go func(chatID int64) {
			defer wg.Done()
			if _, err := c.SendMessage(ctx, chatID, text, parseMode); err != nil {
				return
			}
			mu.Lock()
			ok++
			mu.Unlock()
		}(id)
	}
	wg.Wait()
	return ok
}
