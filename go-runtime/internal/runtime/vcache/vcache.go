// Package vcache is an in-memory cache of user verification status with a 60s TTL.
package vcache

import (
	"strconv"
	"sync"
	"time"
)

const ttl = 60 * time.Second

type entry struct {
	verified bool
	expires  time.Time
}

// Cache maps "bot:user" -> verification entry.
type Cache struct {
	mu sync.RWMutex
	m  map[string]entry
}

// New creates a verification cache.
func New() *Cache {
	return &Cache{m: map[string]entry{}}
}

func formatID(id int64) string {
	return strconv.FormatInt(id, 10)
}

func key(botName string, userID int64) string {
	return botName + ":" + formatID(userID)
}

// Get returns the cached status. Missing/expired returns (false,false).
func (c *Cache) Get(botName string, userID int64) (verified, ok bool) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	e, exists := c.m[key(botName, userID)]
	if !exists || time.Now().After(e.expires) {
		return false, false
	}
	return e.verified, true
}

// Set caches a verification status.
func (c *Cache) Set(botName string, userID int64, verified bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.m[key(botName, userID)] = entry{verified: verified, expires: time.Now().Add(ttl)}
}

// Invalidate removes a user's cached status (e.g. on kicked).
func (c *Cache) Invalidate(botName string, userID int64) {
	c.mu.Lock()
	defer c.mu.Unlock()
	delete(c.m, key(botName, userID))
}
