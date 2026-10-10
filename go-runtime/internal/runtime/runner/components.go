package runner

import (
	"context"

	"github.com/jh/telegram-bots/botd/internal/captcha/render"
	"github.com/jh/telegram-bots/botd/internal/handlers/services"
	"github.com/jh/telegram-bots/botd/internal/runtime/poller"
	"github.com/jh/telegram-bots/botd/internal/runtime/poolmaintainer"
	"github.com/jh/telegram-bots/botd/internal/runtime/processor"
	"github.com/jh/telegram-bots/botd/internal/runtime/reporter"
	"github.com/jh/telegram-bots/botd/internal/runtime/vcache"
	botsrepo "github.com/jh/telegram-bots/botd/internal/storage/bots"
	"github.com/jh/telegram-bots/botd/internal/storage/captchapool"
	"github.com/jh/telegram-bots/botd/internal/storage/logrepo"
	"github.com/jh/telegram-bots/botd/internal/storage/longmanrepo"
	"github.com/jh/telegram-bots/botd/internal/storage/maprepo"
	"github.com/jh/telegram-bots/botd/internal/storage/verifyrepo"
	tgclient "github.com/jh/telegram-bots/botd/internal/tg/client"
)

// components holds the constructed per-bot objects.
type components struct {
	bundle     *services.Bundle
	poller     *poller.Poller
	reporter   *reporter.Reporter
	maintainer *poolmaintainer.Maintainer
}

// buildComponents loads the active bot config and constructs all components.
func buildComponents(ctx context.Context, botName, startedAt string, d Deps) (*components, error) {
	bots := botsrepo.New(d.DB)
	cfg, err := bots.GetActive(ctx, botName)
	if err != nil {
		return nil, err
	}
	if cfg == nil {
		// Bot missing/inactive: surface a retryable error; reconciler also governs.
		return nil, errBotNotActive
	}

	// Per-bot repos.
	verifyRepo := verifyrepo.New(d.DB, cfg.BotName)
	captchaRepo := captchapool.New(d.DB, cfg.BotName)
	logRepo := logrepo.New(d.DB)
	mapRepo := maprepo.New(d.DB)
	longman := longmanrepo.New(d.DB)

	renderer := d.Renderer
	if renderer == nil {
		renderer = render.New()
	}

	// Telegram client with proxy sourced from config.http_proxy.
	tg := tgclient.New(cfg.APIKey, d.Transport, func(ctx context.Context) (string, error) {
		proxy, _ := d.Config.GetGlobalString(ctx, "http_proxy")
		return proxy, nil
	})

	bundle := &services.Bundle{
		BotID:    cfg.ID,
		BotName:  cfg.BotName,
		SuperID:  cfg.SuperAdminID,
		AdminIDs: cfg.AdminIDs,
		Client:   tg,
		Config:   d.Config,
		Verify:   verifyRepo,
		CaptchaP: captchaRepo,
		Log:      logRepo,
		Map:      mapRepo,
		Longman:  longman,
		Renderer: renderer,
	}

	vCache := vcache.New()
	proc := processor.New(bundle, vCache, d.Publisher)
	poll := poller.New(cfg.ID, cfg.BotName, tg, d.Config, proc)

	state := &reporter.State{}
	rep := reporter.New(cfg.BotName, cfg.ID, startedAt, d.DB, d.Config, captchaRepo, logRepo, state)

	// Pool maintainer storage target: captcha_chat_id channel if configured,
	// else super admin private chat with delete-after.
	storageChat := int64(d.Config.GetInt(ctx, "captcha_chat_id", 0))
	deleteAfter := false
	if storageChat <= 0 {
		storageChat = cfg.SuperAdminID
		deleteAfter = true
	}
	var maintainer *poolmaintainer.Maintainer
	if cfg.APIKey != "" && storageChat > 0 {
		maintainer = poolmaintainer.New(cfg.BotName, tg, d.Config, captchaRepo,
			renderer, storageChat, deleteAfter)
	}

	return &components{
		bundle:     bundle,
		poller:     poll,
		reporter:   rep,
		maintainer: maintainer,
	}, nil
}
