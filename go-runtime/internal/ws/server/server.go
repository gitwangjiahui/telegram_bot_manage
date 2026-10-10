// Package server serves /ws and /healthz on a single port.
package server

import (
	"context"
	"encoding/json"
	"net/http"
	"strings"

	"github.com/gorilla/websocket"
	"github.com/jh/telegram-bots/botd/internal/ws/auth"
	"github.com/jh/telegram-bots/botd/internal/ws/hub"
)

// SnapshotFunc returns the current full bot status snapshot for the hello frame.
type SnapshotFunc func() map[string]any

// Server wires the HTTP routes.
type Server struct {
	hub      *hub.Hub
	auth     *auth.Auth
	snapshot SnapshotFunc
	upgrader websocket.Upgrader
}

// New creates a Server.
func New(h *hub.Hub, a *auth.Auth, snapshot SnapshotFunc) *Server {
	return &Server{
		hub:      h,
		auth:     a,
		snapshot: snapshot,
		upgrader: websocket.Upgrader{
			// Origin is not enforced for the admin API; JWT gates access.
			CheckOrigin: func(*http.Request) bool { return true },
		},
	}
}

// Handler returns the HTTP handler exposing /ws and /healthz.
func (s *Server) Handler(dbOK func(ctx context.Context) bool, instanceCount func() int) http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/ws", s.handleWS)
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		ctx, cancel := context.WithTimeout(context.Background(), healthTimeout)
		defer cancel()
		status := map[string]any{
			"status":    "ok",
			"db":        dbOK(ctx),
			"instances": instanceCount(),
		}
		w.WriteHeader(http.StatusOK)
		_ = json.NewEncoder(w).Encode(status)
	})
	return mux
}

func (s *Server) handleWS(w http.ResponseWriter, r *http.Request) {
	token := extractToken(r)
	if !s.auth.Verify(token) {
		http.Error(w, "unauthorized", http.StatusUnauthorized)
		return
	}

	conn, err := s.upgrader.Upgrade(w, r, nil)
	if err != nil {
		return
	}

	readPump := func() {
		defer conn.Close()
		conn.SetReadLimit(readLimit)
		for {
			_, payload, err := conn.ReadMessage()
			if err != nil {
				return
			}
			var msg struct {
				Type string `json:"type"`
			}
			if json.Unmarshal(payload, &msg) == nil && msg.Type == "ping" {
				// Application-level ping -> pong via send buffer.
				s.respondPong(conn)
			}
			// subscribe frames are accepted; this server pushes all channels.
		}
	}

	s.hub.ServeWS(conn, func() map[string]any {
		return s.snapshot()
	}, readPump)
}

func (s *Server) respondPong(conn *websocket.Conn) {
	// Best effort: write a pong text frame directly.
	_ = conn.WriteJSON(map[string]any{"type": "pong"})
}

// extractToken reads ?token=, Sec-WebSocket-Protocol, or Authorization header.
func extractToken(r *http.Request) string {
	if t := r.URL.Query().Get("token"); t != "" {
		return t
	}
	if proto := r.Header.Get("Sec-WebSocket-Protocol"); proto != "" {
		// Format may be "bearer, <jwt>" or just "<jwt>".
		for _, p := range strings.Split(proto, ",") {
			p = strings.TrimSpace(p)
			if p != "" && !strings.EqualFold(p, "bearer") {
				return p
			}
		}
	}
	if h := r.Header.Get("Authorization"); h != "" {
		return strings.TrimSpace(strings.TrimPrefix(h, "Bearer "))
	}
	return ""
}
