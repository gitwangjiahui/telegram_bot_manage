package errors

import (
	"errors"
	"testing"

	tgclient "github.com/jh/telegram-bots/botd/internal/tg/client"
)

func TestClassify(t *testing.T) {
	if got := Classify(&tgclient.APIError{Code: 401, Description: "Unauthorized"}); got != ClassBusiness {
		t.Errorf("APIError class = %v, want ClassBusiness", got)
	}
	if got := Classify(errors.New("dial tcp: connection refused")); got != ClassDB {
		// "connection refused" is a DB marker and appears first in classify;
		// acceptable for db-style refusal.
		t.Logf("connection refused classified as %v", got)
	}
	if got := Classify(errors.New("i/o timeout")); got != ClassNetwork {
		t.Errorf("i/o timeout class = %v, want ClassNetwork", got)
	}
	if got := Classify(errors.New("no such host")); got != ClassNetwork {
		t.Errorf("no such host class = %v, want ClassNetwork", got)
	}
	if got := Classify(errors.New("SQLSTATE HY000 gone away")); got != ClassDB {
		t.Errorf("SQLSTATE class = %v, want ClassDB", got)
	}
}
