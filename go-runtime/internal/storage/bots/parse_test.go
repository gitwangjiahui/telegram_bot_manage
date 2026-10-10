package bots

import "testing"

func TestParseAdmins(t *testing.T) {
	// DECISIONS #1: first super wins.
	super, normals := parseAdmins("100:super,200:normal,300:normal,400:super")
	if super != 100 {
		t.Errorf("super = %d, want 100 (first)", super)
	}
	if len(normals) != 2 || normals[0] != 200 || normals[1] != 300 {
		t.Errorf("normals = %v, want [200 300]", normals)
	}

	if s, n := parseAdmins(""); s != 0 || n != nil {
		t.Errorf("empty parse = (%d,%v), want (0,nil)", s, n)
	}

	if s, n := parseAdmins("  55:super ,, 77:normal "); s != 55 || len(n) != 1 || n[0] != 77 {
		t.Errorf("messy parse = (%d,%v)", s, n)
	}
}
