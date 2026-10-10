package auth

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"testing"
)

func makeJWT(secret, payload string) string {
	header := base64.RawURLEncoding.EncodeToString([]byte(`{"alg":"HS256","typ":"JWT"}`))
	body := base64.RawURLEncoding.EncodeToString([]byte(payload))
	input := header + "." + body
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(input))
	sig := base64.RawURLEncoding.EncodeToString(mac.Sum(nil))
	return input + "." + sig
}

func TestVerify(t *testing.T) {
	const secret = "topsecret"
	a := New(secret, false)

	// Valid token, no exp.
	tok := makeJWT(secret, `{"sub":1}`)
	if !a.Verify(tok) {
		t.Error("valid token rejected")
	}

	// Tampered signature.
	if a.Verify(tok + "x") {
		t.Error("tampered token accepted")
	}

	// Wrong secret.
	other := New("other", false)
	if other.Verify(tok) {
		t.Error("token signed with another secret accepted")
	}

	// Expired.
	expTok := makeJWT(secret, `{"sub":1,"exp":1}`)
	if a.Verify(expTok) {
		t.Error("expired token accepted")
	}

	// Fail-closed: empty secret.
	closed := New("", false)
	if closed.Verify(tok) {
		t.Error("empty-secret auth should fail closed")
	}

	// Auth off: accept anything.
	off := New("", true)
	if !off.Verify("garbage") {
		t.Error("WS_AUTH=off should accept any token")
	}
}
