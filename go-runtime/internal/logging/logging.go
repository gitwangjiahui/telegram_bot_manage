// Package logging configures slog and provides secret masking helpers.
package logging

import (
	"log/slog"
	"os"
	"strings"
)

// Init sets the global slog logger. level is debug/info/error.
func Init(level string) {
	var lvl slog.Level
	switch strings.ToLower(level) {
	case "debug":
		lvl = slog.LevelDebug
	case "error":
		lvl = slog.LevelError
	default:
		lvl = slog.LevelInfo
	}
	h := slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: lvl})
	slog.SetDefault(slog.New(h))
}

// MaskToken masks a Telegram token: first 5 + *** + last 4.
// Safe for short/empty input.
func MaskToken(token string) string {
	token = strings.TrimSpace(token)
	if token == "" {
		return ""
	}
	if len(token) <= 9 {
		return strings.Repeat("*", len(token))
	}
	return token[:5] + "***" + token[len(token)-4:]
}
