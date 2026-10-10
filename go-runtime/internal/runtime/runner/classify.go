package runner

import (
	"errors"

	rterrors "github.com/jh/telegram-bots/botd/internal/runtime/errors"
	"github.com/jh/telegram-bots/botd/internal/tg/transport"
)

type errClass int

const (
	classDB errClass = iota
	classNetwork
	classBusiness
)

// errBotNotActive indicates the bot row is missing or inactive.
var errBotNotActive = errors.New("bot not found or inactive")

func classifyErr(err error) errClass {
	// Missing proxy with direct connection disabled: wait indefinitely for a
	// proxy to appear, never trip the business circuit breaker.
	if errors.Is(err, transport.ErrNoProxy) {
		return classNetwork
	}
	switch rterrors.Classify(err) {
	case rterrors.ClassDB:
		return classDB
	case rterrors.ClassNetwork:
		return classNetwork
	default:
		return classBusiness
	}
}
