// Package services wires the shared dependencies handed to every handler.
package services

import (
	"github.com/jh/telegram-bots/botd/internal/captcha/render"
	"github.com/jh/telegram-bots/botd/internal/config/appconfig"
	"github.com/jh/telegram-bots/botd/internal/storage/captchapool"
	"github.com/jh/telegram-bots/botd/internal/storage/logrepo"
	"github.com/jh/telegram-bots/botd/internal/storage/longmanrepo"
	"github.com/jh/telegram-bots/botd/internal/storage/maprepo"
	"github.com/jh/telegram-bots/botd/internal/storage/verifyrepo"
	tgclient "github.com/jh/telegram-bots/botd/internal/tg/client"
)

// Bundle groups every dependency a handler needs for one bot.
type Bundle struct {
	BotID    int
	BotName  string
	SuperID  int64
	AdminIDs []int64
	Client   *tgclient.Client
	Config   *appconfig.Cache
	Verify   *verifyrepo.Repo
	CaptchaP *captchapool.Repo
	Log      *logrepo.Repo
	Map      *maprepo.Repo
	Longman  *longmanrepo.Repo
	Renderer *render.Renderer
}

// AdminTargets returns unique [super]+[normal] ids, dropping zero/duplicates.
func (b *Bundle) AdminTargets() []int64 {
	seen := map[int64]bool{}
	var out []int64
	add := func(id int64) {
		if id <= 0 || seen[id] {
			return
		}
		seen[id] = true
		out = append(out, id)
	}
	add(b.SuperID)
	for _, id := range b.AdminIDs {
		add(id)
	}
	return out
}
