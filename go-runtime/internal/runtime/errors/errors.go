// Package errors implements the three-way error classification (docs 2.8).
package errors

import (
	"errors"
	"strings"

	tgclient "github.com/jh/telegram-bots/botd/internal/tg/client"
)

// Class is an error category.
type Class int

const (
	// ClassUnknown is a non-telegram, non-db unexpected error (treated as business).
	ClassUnknown Class = iota
	// ClassDB is a database connection/query failure: retry forever, no exit.
	ClassDB
	// ClassNetwork is a telegram transport failure (request never completed): retry forever.
	ClassNetwork
	// ClassBusiness is a real Telegram API error (ok=false / 4xx): counts toward circuit break.
	ClassBusiness
)

// Classify categorizes an error from the poll loop.
func Classify(err error) Class {
	if err == nil {
		return ClassUnknown
	}

	var apiErr *tgclient.APIError
	if errors.As(err, &apiErr) {
		return ClassBusiness
	}

	if isDBError(err) {
		return ClassDB
	}
	if isNetworkError(err) {
		return ClassNetwork
	}
	return ClassBusiness
}

// isDBError matches PHP isDbError on connection-level text.
func isDBError(err error) bool {
	msg := strings.ToLower(err.Error())
	for _, marker := range dbMarkers {
		if strings.Contains(msg, marker) {
			return true
		}
	}
	return false
}

// isNetworkError matches transport-level telegram failures (PHP isNetworkError).
func isNetworkError(err error) bool {
	msg := strings.ToLower(err.Error())

	// net/url or net package timeouts.
	var netErr interface{ Timeout() bool }
	if errors.As(err, &netErr) && netErr.Timeout() {
		return true
	}

	for _, marker := range networkMarkers {
		if strings.Contains(msg, marker) {
			return true
		}
	}
	return false
}

var dbMarkers = []string{
	"sqlstate",
	"gone away",
	"connection refused",
	"can't connect",
	"lost connection",
	"bad conn",
	"invalid connection",
	"driver: bad connection",
}

var networkMarkers = []string{
	"connection reset",
	"connection timed out",
	"encountered end of file",
	"failed to connect",
	"eof",
	"i/o timeout",
	"no such host",
	"tls handshake",
	"dial tcp",
	"server misbehaving",
	"network is unreachable",
	"non-json response",
}
