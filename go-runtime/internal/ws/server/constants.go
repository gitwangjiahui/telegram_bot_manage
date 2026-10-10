package server

import "time"

const (
	healthTimeout = 5 * time.Second
	readLimit     = 4096
)
