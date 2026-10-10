// Package ratelimit handles 429 retry_after backoff helpers.
// DECISIONS #7: no per-chat token bucket this round.
package ratelimit

import (
	"context"
	"time"
)

// Wait blocks for the given seconds (or until ctx is canceled).
// retryAfter defaults to 1 when <= 0.
func Wait(ctx context.Context, retryAfter int) error {
	if retryAfter <= 0 {
		retryAfter = 1
	}
	t := time.NewTimer(time.Duration(retryAfter) * time.Second)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}
