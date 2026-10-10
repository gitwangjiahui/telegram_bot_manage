package poolmaintainer

import (
	"context"
	"errors"
	"strings"
	"time"

	tgclient "github.com/jh/telegram-bots/botd/internal/tg/client"
)

func sleepCtx(ctx context.Context, seconds int) error {
	t := time.NewTimer(time.Duration(seconds) * time.Second)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

// retryAfterOf extracts retry_after from a 429 API error.
// The tg client normally retries 429 internally; this is a defensive fallback
// for a surfaced (e.g. context-bounded) 429.
func retryAfterOf(err error) (int, bool) {
	var apiErr *tgclient.APIError
	if errors.As(err, &apiErr) && apiErr.Code == 429 {
		ra := apiErr.RetryAfter
		if ra <= 0 {
			ra = 1
		}
		return ra, true
	}
	// Some errors arrive as text when not decoded to APIError.
	if strings.Contains(err.Error(), "429") {
		return 1, true
	}
	return 0, false
}
