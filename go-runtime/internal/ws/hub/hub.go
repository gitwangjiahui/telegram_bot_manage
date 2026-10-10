// Package hub registers connections, broadcasts events, disconnects slow
// consumers and sends a 30s control-frame ping (docs 8.4).
package hub

import (
	"encoding/json"
	"log/slog"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"github.com/jh/telegram-bots/botd/internal/ws/events"
)

const (
	sendBufferSize = 256
	pingPeriod     = 30 * time.Second
	writeWait      = 10 * time.Second
)

// frame is the server -> client envelope.
type frame struct {
	Type string         `json:"type"`
	TS   int64          `json:"ts"`
	Data map[string]any `json:"data"`
}

// Hub fans events out to all connections.
type Hub struct {
	mu      sync.Mutex
	clients map[*client]struct{}

	// bot_status throttling: last send per bot.
	statusThrottleWindow time.Duration
	lastStatus           map[string]time.Time
}

// New creates a Hub.
func New() *Hub {
	return &Hub{
		clients:              map[*client]struct{}{},
		statusThrottleWindow: 5 * time.Second,
		lastStatus:           map[string]time.Time{},
	}
}

// ServeWS registers a new connection and runs its write pump.
// Blocks until the connection closes.
func (h *Hub) ServeWS(conn *websocket.Conn, hello func() map[string]any, readPump func()) {
	c := &client{conn: conn, send: make(chan frame, sendBufferSize)}

	h.mu.Lock()
	h.clients[c] = struct{}{}
	h.mu.Unlock()

	defer func() {
		h.mu.Lock()
		delete(h.clients, c)
		h.mu.Unlock()
		close(c.send)
	}()

	// Hello frame.
	c.send <- frame{
		Type: "hello",
		TS:   time.Now().Unix(),
		Data: hello(),
	}

	if readPump != nil {
		// Caller-provided read pump handles ping/subscribe and read deadlines.
		go readPump()
	}

	c.writePump()
}

// Publish fans an event out, dropping slow consumers.
func (h *Hub) Publish(e events.Event) {
	if e.Type == events.BotStatus {
		if !h.shouldSendStatus(e) {
			return
		}
	}

	f := frame{Type: e.Type, TS: e.TS, Data: e.Data}

	h.mu.Lock()
	clients := make([]*client, 0, len(h.clients))
	for c := range h.clients {
		clients = append(clients, c)
	}
	h.mu.Unlock()

	for _, c := range clients {
		select {
		case c.send <- f:
		default:
			// Buffer full: disconnect the slow consumer.
			slog.Warn("ws slow consumer, dropping connection")
			_ = c.conn.Close()
		}
	}
}

// shouldSendStatus throttles bot_status to at most one per window per bot,
// always allowing a status string change through.
func (h *Hub) shouldSendStatus(e events.Event) bool {
	name, _ := e.Data["bot_name"].(string)
	status, _ := e.Data["status"].(string)

	h.mu.Lock()
	defer h.mu.Unlock()

	last, ok := h.lastStatus[name]
	key := name + "|" + status
	_ = key
	if ok && time.Since(last) < h.statusThrottleWindow {
		return false
	}
	h.lastStatus[name] = time.Now()
	return true
}

type client struct {
	conn *websocket.Conn
	send chan frame
}

func (c *client) writePump() {
	ticker := time.NewTicker(pingPeriod)
	defer ticker.Stop()

	for {
		select {
		case f, ok := <-c.send:
			_ = c.conn.SetWriteDeadline(time.Now().Add(writeWait))
			if !ok {
				_ = c.conn.WriteMessage(websocket.CloseMessage, []byte{})
				return
			}
			data, err := json.Marshal(f)
			if err != nil {
				return
			}
			if err := c.conn.WriteMessage(websocket.TextMessage, data); err != nil {
				return
			}
		case <-ticker.C:
			_ = c.conn.SetWriteDeadline(time.Now().Add(writeWait))
			if err := c.conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}
