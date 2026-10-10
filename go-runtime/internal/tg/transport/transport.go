// Package transport provides a shared HTTP transport honoring the mandatory proxy.
package transport

import (
	"errors"
	"net/http"
	"net/url"
	"sync"
	"time"
)

// ErrNoProxy is returned when no proxy is configured but direct connections
// are disallowed.
var ErrNoProxy = errors.New("no http_proxy configured and direct connection is disabled")

// Manager builds shared http.Clients from the current proxy value.
// Direct connection is refused unless allowDirect is true (fail-closed).
type Manager struct {
	allowDirect bool

	mu     sync.Mutex
	proxy  string
	client *http.Client
}

// NewManager creates a transport manager.
func NewManager(allowDirect bool) *Manager {
	return &Manager{allowDirect: allowDirect}
}

// Client returns an http.Client for the given proxy. If the proxy is unchanged
// the previously built client is reused. Returns ErrNoProxy when proxy is empty
// and direct connections are disallowed.
func (m *Manager) Client(proxy string) (*http.Client, error) {
	m.mu.Lock()
	defer m.mu.Unlock()

	if proxy == "" && !m.allowDirect {
		return nil, ErrNoProxy
	}
	if m.client != nil && m.proxy == proxy {
		return m.client, nil
	}

	t := &http.Transport{
		Proxy:               proxyFunc(proxy),
		MaxIdleConns:        100,
		MaxIdleConnsPerHost: 20,
		IdleConnTimeout:     90 * time.Second,
	}
	m.client = &http.Client{Transport: t}
	m.proxy = proxy
	return m.client, nil
}

func proxyFunc(proxy string) func(*http.Request) (*url.URL, error) {
	if proxy == "" {
		return http.ProxyFromEnvironment
	}
	u, err := url.Parse(proxy)
	if err != nil {
		return func(*http.Request) (*url.URL, error) { return nil, err }
	}
	return http.ProxyURL(u)
}
