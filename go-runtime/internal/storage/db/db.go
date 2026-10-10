// Package db manages the MySQL connection pool and health checks.
package db

import (
	"context"
	"database/sql"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

// Open creates a pooled MySQL connection. Ping is attempted but a failure
// does not prevent returning the pool (caller retries health checks).
func Open(dsn string) (*sql.DB, error) {
	database, err := sql.Open("mysql", dsn)
	if err != nil {
		return nil, err
	}
	database.SetMaxOpenConns(20)
	database.SetMaxIdleConns(5)
	database.SetConnMaxLifetime(30 * time.Minute)
	return database, nil
}

// Ping runs SELECT 1.
func Ping(ctx context.Context, database *sql.DB) error {
	pingCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	return database.PingContext(pingCtx)
}
