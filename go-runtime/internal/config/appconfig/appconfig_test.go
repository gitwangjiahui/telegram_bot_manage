package appconfig

import "testing"

func TestParseJSONInt(t *testing.T) {
	if n, ok := parseJSONInt("42"); !ok || n != 42 {
		t.Errorf("parseJSONInt(42) = (%d,%v)", n, ok)
	}
	if _, ok := parseJSONInt(`"abc"`); ok {
		t.Error("quoted string should not parse as int")
	}
	if _, ok := parseJSONInt("not json"); ok {
		t.Error("raw string should not parse as json int")
	}
}
