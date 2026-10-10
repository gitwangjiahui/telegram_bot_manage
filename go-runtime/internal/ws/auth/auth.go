// Package auth verifies Node-issued JWTs for WebSocket access.
// Fail-closed: without a secret every request is rejected unless WS_AUTH=off.
package auth

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"strings"
	"time"
)

// Auth validates JWT access.
type Auth struct {
	secret []byte
	off    bool
}

// New creates an Auth. off=true disables verification (WS_AUTH=off, debug only).
func New(secret string, off bool) *Auth {
	return &Auth{secret: []byte(secret), off: off}
}

// Claims is the minimal JWT payload inspected.
type Claims struct {
	Exp int64 `json:"exp"`
	Iat int64 `json:"iat"`
	Sub any   `json:"sub"`
}

// Verify validates an HS256 JWT and its expiry. Returns true on success.
func (a *Auth) Verify(token string) bool {
	if a.off {
		return true
	}
	if len(a.secret) == 0 {
		// Fail-closed: no secret configured.
		return false
	}

	token = strings.TrimSpace(token)
	parts := strings.Split(token, ".")
	if len(parts) != 3 {
		return false
	}

	signingInput := parts[0] + "." + parts[1]
	if !validMAC(signingInput, parts[2], a.secret) {
		return false
	}

	payload, err := base64.RawURLEncoding.DecodeString(parts[1])
	if err != nil {
		return false
	}
	var claims Claims
	if err := json.Unmarshal(payload, &claims); err != nil {
		return false
	}
	if claims.Exp > 0 && time.Now().Unix() > claims.Exp {
		return false
	}
	return true
}

func validMAC(input, signature string, secret []byte) bool {
	mac := hmac.New(sha256.New, secret)
	mac.Write([]byte(input))
	expected := mac.Sum(nil)
	got, err := base64.RawURLEncoding.DecodeString(signature)
	if err != nil {
		return false
	}
	return hmac.Equal(got, expected)
}
