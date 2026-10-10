// Package loader reads process configuration from environment variables.
package loader

import (
	"fmt"
	"net/url"
	"os"
	"strconv"
	"strings"
)

// Config is the process-level configuration sourced from env (and optional flags).
type Config struct {
	Listen              string
	DBHost              string
	DBPort              string
	DBName              string
	DBUser              string
	DBPassword          string
	LogLevel            string
	ReconcileInterval   int // seconds
	ControlPollInterval int // seconds
	AllowDirect         bool
	JWTSecret           string
	WSAuth              string // "" => JWT required; "off" => no auth (debug only)
	BotdOnly            []string
}

// FromEnv loads configuration from the process environment.
func FromEnv() *Config {
	c := &Config{
		Listen:              get("BOTD_LISTEN", "127.0.0.1:19090"),
		DBHost:              get("DB_HOST", "127.0.0.1"),
		DBPort:              get("DB_PORT", "3306"),
		DBName:              get("DB_NAME", ""),
		DBUser:              get("DB_USER", ""),
		DBPassword:          get("DB_PASSWORD", ""),
		LogLevel:            get("LOG_LEVEL", "info"),
		ReconcileInterval:   getInt("RECONCILE_INTERVAL", 30),
		ControlPollInterval: getInt("CONTROL_POLL_INTERVAL", 1),
		AllowDirect:         getBool("ALLOW_DIRECT", false),
		JWTSecret:           get("JWT_SECRET", ""),
		WSAuth:              get("WS_AUTH", ""),
	}
	if only := get("BOTD_ONLY", ""); only != "" {
		for _, b := range strings.Split(only, ",") {
			if b = strings.TrimSpace(b); b != "" {
				c.BotdOnly = append(c.BotdOnly, b)
			}
		}
	}
	if c.ReconcileInterval < 5 {
		c.ReconcileInterval = 5
	}
	if c.ControlPollInterval < 1 {
		c.ControlPollInterval = 1
	}
	return c
}

// DSN builds the MySQL driver DSN.
func (c *Config) DSN() string {
	return fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?charset=utf8mb4&parseTime=true&loc=%s&timeout=5s&readTimeout=30s&writeTimeout=30s",
		c.DBUser, c.DBPassword, c.DBHost, c.DBPort, c.DBName, url.QueryEscape("Asia/Shanghai"))
}

func get(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func getInt(key string, def int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(strings.TrimSpace(v)); err == nil {
			return n
		}
	}
	return def
}

func getBool(key string, def bool) bool {
	if v := os.Getenv(key); v != "" {
		switch strings.ToLower(strings.TrimSpace(v)) {
		case "true", "1", "yes", "on":
			return true
		case "false", "0", "no", "off":
			return false
		}
	}
	return def
}
