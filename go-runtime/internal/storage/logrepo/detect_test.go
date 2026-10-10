package logrepo

import (
	"testing"

	"github.com/jh/telegram-bots/botd/internal/tg/types"
)

func TestDetectType(t *testing.T) {
	cases := []struct {
		name string
		m    types.Message
		want string
	}{
		{"photo", types.Message{Photo: []types.PhotoSize{{}}}, "photo"},
		{"voice", types.Message{Voice: &types.Voice{}}, "voice"},
		{"video beats document", types.Message{Video: &types.Video{}, Document: &types.Document{}}, "video"},
		{"text", types.Message{Text: "hi"}, "text"},
		{"other", types.Message{}, "other"},
		{"sticker", types.Message{Sticker: &types.Sticker{}}, "sticker"},
	}
	for _, c := range cases {
		if got := DetectType(&c.m); got != c.want {
			t.Errorf("%s: DetectType = %q, want %q", c.name, got, c.want)
		}
	}
}
