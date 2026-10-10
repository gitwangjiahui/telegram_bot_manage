package mathgen

import (
	"regexp"
	"strconv"
	"strings"
	"testing"
)

func TestNew(t *testing.T) {
	re := regexp.MustCompile(`^(\d+) \+ (\d+) = \?$`)
	for i := 0; i < 100; i++ {
		q := New()
		m := re.FindStringSubmatch(q.Question)
		if m == nil {
			t.Fatalf("bad question format: %q", q.Question)
		}
		a, _ := strconv.Atoi(m[1])
		b, _ := strconv.Atoi(m[2])
		if a < 10 || a > 99 || b < 10 || b > 99 {
			t.Fatalf("operand out of range: %d,%d", a, b)
		}
		want := strconv.Itoa(a + b)
		if q.Answer != want {
			t.Errorf("answer = %s, want %s", q.Answer, want)
		}
		if strings.TrimSpace(q.Answer) == "" {
			t.Error("empty answer")
		}
	}
}
