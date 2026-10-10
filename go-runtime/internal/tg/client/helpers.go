package client

import (
	"context"
	"time"
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

func duration(seconds int) time.Duration {
	return time.Duration(seconds) * time.Second
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
