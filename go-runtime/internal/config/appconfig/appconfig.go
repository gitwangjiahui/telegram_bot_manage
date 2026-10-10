// Package appconfig reads the `config` table with a 60s TTL cache,
// matching PHP utils/Config.php parse semantics (JSON-or-raw).
package appconfig

import (
	"context"
	"database/sql"
	"encoding/json"
	"strconv"
	"strings"
	"sync"
	"time"
)

const ttl = 60 * time.Second

// Cache caches global and per-bot config values.
type Cache struct {
	db *sql.DB

	mu      sync.RWMutex
	loaded  time.Time
	global  map[string]string
	byBot   map[int]map[string]string
	offsets map[int]int64 // in-memory authoritative last_update_id
}

// New creates a config cache.
func New(db *sql.DB) *Cache {
	return &Cache{
		db:      db,
		global:  map[string]string{},
		byBot:   map[int]map[string]string{},
		offsets: map[int]int64{},
	}
}

// Invalidate forces a reload on the next read.
func (c *Cache) Invalidate() {
	c.mu.Lock()
	c.loaded = time.Time{}
	c.mu.Unlock()
}

func (c *Cache) ensureFresh(ctx context.Context) error {
	c.mu.RLock()
	fresh := time.Since(c.loaded) < ttl
	c.mu.RUnlock()
	if fresh {
		return nil
	}
	return c.reload(ctx)
}

func (c *Cache) reload(ctx context.Context) error {
	rows, err := c.db.QueryContext(ctx,
		"SELECT bot_id, config_key, config_value FROM config")
	if err != nil {
		return err
	}
	defer rows.Close()

	global := map[string]string{}
	byBot := map[int]map[string]string{}
	for rows.Next() {
		var botID sql.NullInt64
		var key string
		var val sql.NullString
		if err := rows.Scan(&botID, &key, &val); err != nil {
			return err
		}
		raw := ""
		if val.Valid {
			raw = val.String
		}
		if botID.Valid {
			id := int(botID.Int64)
			m := byBot[id]
			if m == nil {
				m = map[string]string{}
			}
			m[key] = raw
			byBot[id] = m
		} else {
			global[key] = raw
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}

	c.mu.Lock()
	c.global = global
	c.byBot = byBot
	c.loaded = time.Now()
	c.mu.Unlock()
	return nil
}

// GetGlobalString returns a global raw string value.
func (c *Cache) GetGlobalString(ctx context.Context, key string) (string, bool) {
	if err := c.ensureFresh(ctx); err != nil {
		return "", false
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	v, ok := c.global[key]
	return v, ok
}

// GetInt reads a global integer key, tolerating JSON-number or bare-number strings.
func (c *Cache) GetInt(ctx context.Context, key string, def int) int {
	v, ok := c.GetGlobalString(ctx, key)
	if !ok {
		return def
	}
	if n, ok := parseJSONInt(v); ok {
		return n
	}
	if n, err := strconv.Atoi(strings.TrimSpace(v)); err == nil {
		return n
	}
	return def
}

// GetAutoReply resolves the auto reply: bot-level non-empty -> global non-empty -> hardcoded fallback.
func (c *Cache) GetAutoReply(ctx context.Context, botID int) string {
	const hardcoded = "消息已转发，请等待回复。"
	if err := c.ensureFresh(ctx); err != nil {
		return hardcoded
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	if m := c.byBot[botID]; m != nil {
		if v := strings.TrimSpace(m["auto_reply_message"]); v != "" {
			return v
		}
	}
	if v := strings.TrimSpace(c.global["auto_reply_message"]); v != "" {
		return v
	}
	return hardcoded
}

// GetOffset returns the persisted update offset for a bot.
// Returns (0,false) if no offset exists.
func (c *Cache) GetOffset(ctx context.Context, botID int) (int64, bool) {
	c.mu.RLock()
	if v, ok := c.offsets[botID]; ok {
		c.mu.RUnlock()
		return v, true
	}
	c.mu.RUnlock()

	if err := c.ensureFresh(ctx); err != nil {
		return 0, false
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	m := c.byBot[botID]
	if m == nil {
		return 0, false
	}
	v, ok := m["last_update_id"]
	if !ok {
		return 0, false
	}
	n, err := strconv.ParseInt(strings.TrimSpace(v), 10, 64)
	if err != nil {
		return 0, false
	}
	return n, true
}

// SaveOffset persists last_update_id to the config table and updates the in-memory site.
// On write failure the in-memory site is NOT advanced (at-least-once).
func (c *Cache) SaveOffset(ctx context.Context, botID int, offset int64) error {
	_, err := c.db.ExecContext(ctx,
		`INSERT INTO config (bot_id, config_key, config_value) VALUES (?, 'last_update_id', ?)
		 ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)`,
		botID, strconv.FormatInt(offset, 10))
	if err != nil {
		return err
	}
	c.mu.Lock()
	c.offsets[botID] = offset
	if m := c.byBot[botID]; m != nil {
		m["last_update_id"] = strconv.FormatInt(offset, 10)
	} else {
		c.byBot[botID] = map[string]string{"last_update_id": strconv.FormatInt(offset, 10)}
	}
	c.mu.Unlock()
	return nil
}

// parseValue mirrors PHP Config::parseValue: try JSON decode first.
// Returns the decoded value if it is a scalar; callers needing raw use the raw string.
func parseJSONInt(s string) (int, bool) {
	var n int
	if err := json.Unmarshal([]byte(s), &n); err == nil {
		return n, true
	}
	return 0, false
}
