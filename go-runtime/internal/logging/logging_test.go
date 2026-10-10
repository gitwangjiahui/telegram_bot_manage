package logging

import "testing"

func TestMaskToken(t *testing.T) {
	cases := []struct {
		in   string
		want string
	}{
		{"1234567890", "12345***7890"},
		{"short", "*****"},
		{"", ""},
		{"12345ABCDE6789", "12345***6789"},
	}
	for _, c := range cases {
		if got := MaskToken(c.in); got != c.want {
			t.Errorf("MaskToken(%q) = %q, want %q", c.in, got, c.want)
		}
	}
}
