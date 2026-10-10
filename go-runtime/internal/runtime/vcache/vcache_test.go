package vcache

import "testing"

func TestCache(t *testing.T) {
	c := New()
	if _, ok := c.Get("bot1", 1); ok {
		t.Error("missing key should not be cached")
	}
	c.Set("bot1", 1, true)
	if v, ok := c.Get("bot1", 1); !ok || !v {
		t.Errorf("get after set = (%v,%v), want (true,true)", v, ok)
	}
	c.Invalidate("bot1", 1)
	if _, ok := c.Get("bot1", 1); ok {
		t.Error("key should be removed after invalidate")
	}
}
