// Package render draws captcha PNGs in pure Go.
//
// The font is gomono.TTF from golang.org/x/image/font/gofont/gomono (no external
// TTF file), parsed with golang.org/x/image/font/opentype and drawn with the
// x/image font drawer (freetype rasterizer).
package render

import (
	"bytes"
	"crypto/rand"
	"encoding/binary"
	"image"
	"image/color"
	"image/png"
	"sync"

	"golang.org/x/image/font"
	"golang.org/x/image/font/gofont/gomono"
	"golang.org/x/image/font/opentype"
	"golang.org/x/image/math/fixed"
)

const (
	width  = 300
	height = 100
	// fontSize approximates the visual weight of GD font5 within the 300x100
	// canvas (doc 5.2).
	fontSize = 22
	dpi      = 72
	nLines   = 5
)

// Renderer renders captcha questions to PNG.
type Renderer struct {
	once sync.Once
	face font.Face
	err  error
}

// New creates a Renderer.
func New() *Renderer { return &Renderer{} }

func (r *Renderer) init() {
	r.once.Do(func() {
		f, err := opentype.Parse(gomono.TTF)
		if err != nil {
			r.err = err
			return
		}
		face, err := opentype.NewFace(f, &opentype.FaceOptions{
			Size:    fontSize,
			DPI:     dpi,
			Hinting: font.HintingFull,
		})
		if err != nil {
			r.err = err
			return
		}
		r.face = face
	})
}

// Render draws the question and returns PNG bytes.
func (r *Renderer) Render(question string) ([]byte, error) {
	r.init()
	if r.err != nil {
		return nil, r.err
	}

	img := image.NewRGBA(image.Rect(0, 0, width, height))

	// Background RGB(240,240,240).
	bg := color.RGBA{R: 240, G: 240, B: 240, A: 255}
	for y := 0; y < height; y++ {
		for x := 0; x < width; x++ {
			img.Set(x, y, bg)
		}
	}

	// 5 interference lines, endpoints within canvas, RGB channels in [100,200].
	for i := 0; i < nLines; i++ {
		drawLine(img,
			randInt(0, width), randInt(0, height),
			randInt(0, width), randInt(0, height),
			color.RGBA{
				R: uint8(randInt(100, 200)),
				G: uint8(randInt(100, 200)),
				B: uint8(randInt(100, 200)),
				A: 255,
			})
	}

	// Text color, RGB channels in [30,80].
	textCol := color.RGBA{
		R: uint8(randInt(30, 80)),
		G: uint8(randInt(30, 80)),
		B: uint8(randInt(30, 80)),
		A: 255,
	}

	d := &font.Drawer{
		Dst:  img,
		Src:  image.NewUniform(textCol),
		Face: r.face,
	}
	advance := d.MeasureString(question)
	metrics := r.face.Metrics()

	x := (fixed.I(width) - advance) / 2
	// Vertically center based on ascent/descent around the baseline.
	textHeight := metrics.Ascent + metrics.Descent
	baseline := fixed.I(height)/2 + textHeight/2 - metrics.Descent
	d.Dot = fixed.Point26_6{X: x, Y: baseline}
	d.DrawString(question)

	var buf bytes.Buffer
	if err := png.Encode(&buf, img); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

// drawLine is a simple Bresenham line.
func drawLine(img *image.RGBA, x0, y0, x1, y1 int, c color.Color) {
	dx := absInt(x1 - x0)
	dy := -absInt(y1 - y0)
	sx := step(x0, x1)
	sy := step(y0, y1)
	err := dx + dy
	for {
		img.Set(x0, y0, c)
		if x0 == x1 && y0 == y1 {
			return
		}
		e2 := 2 * err
		if e2 >= dy {
			err += dy
			x0 += sx
		}
		if e2 <= dx {
			err += dx
			y0 += sy
		}
	}
}

func step(a, b int) int {
	if a < b {
		return 1
	}
	return -1
}

func absInt(n int) int {
	if n < 0 {
		return -n
	}
	return n
}

// randInt returns a cryptographic-random int in [min,max].
func randInt(min, max int) int {
	if max <= min {
		return min
	}
	var b [8]byte
	if _, err := rand.Read(b[:]); err != nil {
		return min
	}
	n := binary.LittleEndian.Uint64(b[:])
	return min + int(n%uint64(max-min+1))
}
