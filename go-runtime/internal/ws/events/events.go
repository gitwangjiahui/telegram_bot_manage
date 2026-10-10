// Package events defines the in-process event bus carrying runtime events to
// the WebSocket hub. Handlers depend only on the Publisher interface, never on ws.
package events

import (
	"sync"
	"time"
)

// Event type constants (docs 8.3).
const (
	BotStatus    = "bot_status"
	BotLifecycle = "bot_lifecycle"
	MessageNew   = "message_new"
	MessageOut   = "message_out"
	UserVerified = "user_verified"
)

// Event is a bus event.
type Event struct {
	Type string
	TS   int64
	Data map[string]any
}

// New builds an event timestamped now.
func New(typ string, data map[string]any) Event {
	return Event{Type: typ, TS: time.Now().Unix(), Data: data}
}

// Publisher is the minimal interface handlers use.
type Publisher interface {
	Publish(Event)
}

// Sink receives events (the ws hub registers one).
type Sink func(Event)

// Bus is a simple fan-out event bus.
type Bus struct {
	mu    sync.RWMutex
	sinks []Sink
}

// NewBus creates an event bus.
func NewBus() *Bus { return &Bus{} }

// Subscribe registers a sink.
func (b *Bus) Subscribe(s Sink) {
	b.mu.Lock()
	b.sinks = append(b.sinks, s)
	b.mu.Unlock()
}

// Publish fans an event out to all sinks. Delivery to a sink must not block;
// sinks are expected to buffer internally.
func (b *Bus) Publish(e Event) {
	b.mu.RLock()
	sinks := make([]Sink, len(b.sinks))
	copy(sinks, b.sinks)
	b.mu.RUnlock()
	for _, s := range sinks {
		s(e)
	}
}
