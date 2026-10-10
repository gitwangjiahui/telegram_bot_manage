// Package mathgen generates two-digit addition captchas.
package mathgen

import (
	"crypto/rand"
	"fmt"
	"math/big"
)

// Question is a generated captcha.
type Question struct {
	Question string // e.g. "47 + 25 = ?"
	Answer   string // e.g. "72"
}

// New generates a fresh addition question with a,b in [10,99].
func New() Question {
	a := randInt(10, 99)
	b := randInt(10, 99)
	return Question{
		Question: fmt.Sprintf("%d + %d = ?", a, b),
		Answer:   fmt.Sprintf("%d", a+b),
	}
}

func randInt(min, max int) int {
	n, err := rand.Int(rand.Reader, big.NewInt(int64(max-min+1)))
	if err != nil {
		// crypto/rand failure is extraordinarily unlikely; fall back to a fixed
		// in-range value rather than panic.
		return min
	}
	return min + int(n.Int64())
}
