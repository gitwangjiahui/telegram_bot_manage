// Package poolmaintainer maintains the captcha pre-generation pool for one bot
// (replaces utils/CaptchaPoolWorker.php).
package poolmaintainer

import (
	"context"
	"log/slog"
	"sync"
	"time"

	"github.com/jh/telegram-bots/botd/internal/captcha/mathgen"
	"github.com/jh/telegram-bots/botd/internal/captcha/render"
	"github.com/jh/telegram-bots/botd/internal/config/appconfig"
	"github.com/jh/telegram-bots/botd/internal/storage/captchapool"
	tgclient "github.com/jh/telegram-bots/botd/internal/tg/client"
)

const (
	loopTick     = 10 * time.Second
	batchChannel = 8
	batchChat    = 3
	defaultNum   = 100
)

// Maintainer keeps the pool filled for one bot.
type Maintainer struct {
	botName     string
	client      *tgclient.Client
	config      *appconfig.Cache
	pool        *captchapool.Repo
	renderer    *render.Renderer
	storageChat int64
	deleteAfter bool
}

// New creates a Maintainer.
func New(botName string, c *tgclient.Client, cfg *appconfig.Cache,
	pool *captchapool.Repo, renderer *render.Renderer, storageChat int64, deleteAfter bool) *Maintainer {
	return &Maintainer{
		botName:     botName,
		client:      c,
		config:      cfg,
		pool:        pool,
		renderer:    renderer,
		storageChat: storageChat,
		deleteAfter: deleteAfter,
	}
}

// Run loops until ctx is canceled. DB/network errors never terminate it.
func (m *Maintainer) Run(ctx context.Context) {
	slog.Info("captcha pool maintainer started", "bot", m.botName, "component", "POOL")
	t := time.NewTicker(loopTick)
	defer t.Stop()

	m.tick(ctx) // run immediately at startup for pre-generation
	for {
		select {
		case <-ctx.Done():
			slog.Info("captcha pool maintainer stopped", "bot", m.botName, "component", "POOL")
			return
		case <-t.C:
			m.tick(ctx)
		}
	}
}

func (m *Maintainer) tick(ctx context.Context) {
	target := m.config.GetInt(ctx, "verify_code_pre_gen_num", defaultNum)
	if target <= 0 {
		target = defaultNum
	}
	available, err := m.pool.CountAvailable(ctx)
	if err != nil {
		slog.Warn("pool count failed, retrying next tick", "bot", m.botName,
			"err", err.Error())
		return
	}

	if available < target {
		need := target - available
		made := m.refill(ctx, need)
		slog.Info("refill complete", "bot", m.botName, "component", "POOL",
			"need", need, "made", made, "available", available)
	}

	if _, err := m.pool.PruneUsed(ctx, 3); err != nil {
		slog.Warn("prune used failed", "bot", m.botName, "err", err.Error())
	}
}

func (m *Maintainer) refill(ctx context.Context, need int) int {
	made := 0
	batchSize := batchChannel
	if m.deleteAfter {
		batchSize = batchChat
	}

	for need > 0 && ctx.Err() == nil {
		size := batchSize
		if size > need {
			size = need
		}

		// Generate questions + images in memory.
		type batchItem struct {
			q   mathgen.Question
			png []byte
		}
		batch := make([]batchItem, size)
		var wg sync.WaitGroup
		for i := 0; i < size; i++ {
			wg.Add(1)
			go func(i int) {
				defer wg.Done()
				q := mathgen.New()
				png, err := m.renderer.Render(q.Question)
				if err != nil {
					slog.Error("render failed", "bot", m.botName, "err", err.Error())
					return
				}
				batch[i] = batchItem{q: q, png: png}
			}(i)
		}
		wg.Wait()

		// Concurrent uploads; collect successes + message ids + any 429.
		var (
			mu         sync.Mutex
			items      []captchapool.Inserted
			toDelete   []int64
			retryAfter int
			got429     bool
			upWG       sync.WaitGroup
		)
		for i := 0; i < size; i++ {
			if batch[i].png == nil {
				continue
			}
			upWG.Add(1)
			go func(it batchItem) {
				defer upWG.Done()
				msgID, photo, err := m.client.SendPhotoUpload(ctx, m.storageChat, it.png, "")
				if err != nil {
					if ra, ok := retryAfterOf(err); ok {
						mu.Lock()
						got429 = true
						if ra > retryAfter {
							retryAfter = ra
						}
						mu.Unlock()
					}
					return
				}
				mu.Lock()
				items = append(items, captchapool.Inserted{
					FileID: photo.FileID,
					Code:   it.q.Question,
					Answer: it.q.Answer,
				})
				toDelete = append(toDelete, msgID)
				mu.Unlock()
			}(batch[i])
		}
		upWG.Wait()

		if len(items) > 0 {
			n, err := m.pool.InsertMany(ctx, items)
			if err != nil {
				slog.Warn("insert many failed", "bot", m.botName, "err", err.Error())
			}
			made += n
		}

		// Super private chat fallback: delete messages to avoid pile-up.
		if m.deleteAfter && len(toDelete) > 0 {
			for _, mid := range toDelete {
				if err := m.client.DeleteMessage(ctx, m.storageChat, mid); err != nil {
					slog.Debug("delete storage message failed", "bot", m.botName,
						"err", err.Error())
				}
			}
		}

		if got429 {
			if retryAfter <= 0 {
				retryAfter = 1
			}
			slog.Info("rate limited during refill, backing off", "bot", m.botName,
				"component", "POOL", "retry_after", retryAfter)
			if err := sleepCtx(ctx, retryAfter); err != nil {
				return made
			}
			continue // need not decremented
		}

		need -= size
	}
	return made
}
