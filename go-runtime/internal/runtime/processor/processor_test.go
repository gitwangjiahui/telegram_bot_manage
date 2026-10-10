package processor

import "testing"

func TestIsStartCommand(t *testing.T) {
	cases := []struct {
		in   string
		want bool
	}{
		{"/start", true},
		{"/start foo", true},
		{"/start@mybot", true},
		{"  /start  ", true},
		{"hello", false},
		{"/startx", false},
		{"", false},
	}
	for _, c := range cases {
		if got := isStartCommand(c.in); got != c.want {
			t.Errorf("isStartCommand(%q) = %v, want %v", c.in, got, c.want)
		}
	}
}

func TestIsNumeric(t *testing.T) {
	if !isNumeric("12345") {
		t.Error("12345 should be numeric")
	}
	if isNumeric("12a") {
		t.Error("12a should not be numeric")
	}
	if isNumeric("") {
		t.Error("empty should not be numeric")
	}
}
