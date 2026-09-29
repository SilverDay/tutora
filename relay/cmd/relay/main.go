// Command relay is Tutora's realtime fan-out relay.
//
// Environment:
//
//	RELAY_PUBLIC_ADDR     listen address for the WebSocket endpoint (default 127.0.0.1:8090;
//	                      Apache reverse-proxies /ws here)
//	RELAY_INTERNAL_ADDR   listen address for the internal API (default 127.0.0.1:8081;
//	                      private network only)
//	RELAY_TOKEN_KEY       64 hex chars, same value as the PHP app's RELAY_TOKEN_KEY
//	RELAY_INTERNAL_SECRET shared secret for POST /internal/broadcast (>= 32 chars)
//	ALLOWED_ORIGINS       comma-separated exact browser origins
package main

import (
	"context"
	"encoding/hex"
	"errors"
	"log"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/silverday/tutora/relay/internal/relay"
)

func main() {
	logger := log.New(os.Stderr, "relay ", log.LstdFlags|log.LUTC)
	cfg, publicAddr, internalAddr, err := configFromEnv()
	if err != nil {
		logger.Fatalf("configuration error: %v", err)
	}
	cfg.Logger = logger
	srv := relay.NewServer(cfg)

	public := &http.Server{Addr: publicAddr, Handler: srv.PublicHandler(), ReadHeaderTimeout: 5 * time.Second, IdleTimeout: 60 * time.Second, MaxHeaderBytes: 16 << 10}
	internal := &http.Server{Addr: internalAddr, Handler: srv.InternalHandler(), ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: 10 * time.Second, MaxHeaderBytes: 16 << 10}

	errs := make(chan error, 2)
	for _, s := range []*http.Server{public, internal} {
		go func(s *http.Server) {
			logger.Printf("listening on %s", s.Addr)
			if err := s.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
				errs <- err
			}
		}(s)
	}

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	select {
	case err := <-errs:
		logger.Printf("server error: %v", err)
	case sig := <-stop:
		logger.Printf("shutting down on %s", sig)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = internal.Shutdown(ctx)
	_ = public.Shutdown(ctx)
}

func configFromEnv() (relay.Config, string, string, error) {
	var cfg relay.Config
	key, err := hex.DecodeString(os.Getenv("RELAY_TOKEN_KEY"))
	if err != nil || len(key) != 32 {
		return cfg, "", "", errors.New("RELAY_TOKEN_KEY must be 64 hex characters")
	}
	secret := os.Getenv("RELAY_INTERNAL_SECRET")
	if len(secret) < 32 {
		return cfg, "", "", errors.New("RELAY_INTERNAL_SECRET must be at least 32 characters")
	}
	var origins []string
	for _, o := range strings.Split(os.Getenv("ALLOWED_ORIGINS"), ",") {
		if o = strings.TrimSpace(o); o != "" {
			origins = append(origins, o)
		}
	}
	if len(origins) == 0 {
		return cfg, "", "", errors.New("ALLOWED_ORIGINS must list at least one origin")
	}
	cfg = relay.Config{TokenKey: key, InternalSecret: secret, AllowedOrigins: origins, Limits: relay.DefaultLimits()}
	return cfg, envOr("RELAY_PUBLIC_ADDR", "127.0.0.1:8090"), envOr("RELAY_INTERNAL_ADDR", "127.0.0.1:8081"), nil
}

func envOr(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}
