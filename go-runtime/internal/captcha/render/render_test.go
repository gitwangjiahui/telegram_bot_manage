package render

import (
	"bytes"
	"image/png"
	"testing"
)

func TestRender(t *testing.T) {
	r := New()
	pngBytes, err := r.Render("47 + 25 = ?")
	if err != nil {
		t.Fatalf("render failed: %v", err)
	}
	if len(pngBytes) == 0 {
		t.Fatal("empty png")
	}
	img, err := png.Decode(bytes.NewReader(pngBytes))
	if err != nil {
		t.Fatalf("output is not a valid png: %v", err)
	}
	b := img.Bounds()
	if b.Dx() != 300 || b.Dy() != 100 {
		t.Errorf("canvas = %dx%d, want 300x100", b.Dx(), b.Dy())
	}
}
