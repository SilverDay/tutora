package relay

import (
	"crypto/sha256"
	"crypto/subtle"
	"encoding/json"
	"io"
	"log"
	"net/http"
	"strings"
	"time"

	"github.com/gorilla/websocket"
)

const (
	defaultAuthDeadline = 5 * time.Second
	pingInterval        = 25 * time.Second
	readTimeout         = 60 * time.Second
	writeTimeout        = 10 * time.Second

	// application close codes (4000-4999)
	closeAuthFailed   = 4401
	closeAuthTimeout  = 4408
	closeLimit        = 4429
	closeSessionEnded = 4410
)

// Config for the relay. Keys and secrets come from the environment (never logged).
type Config struct {
	TokenKey       []byte
	InternalSecret string
	AllowedOrigins []string
	Limits         Limits
	// AuthDeadline for the first (auth) message; defaults to 5 s.
	AuthDeadline time.Duration
	Now          func() time.Time
	Logger       *log.Logger
}

// Server exposes the public WebSocket endpoint and the private internal API.
type Server struct {
	cfg      Config
	hub      *Hub
	upgrader websocket.Upgrader
	origins  map[string]bool
}

func NewServer(cfg Config) *Server {
	if cfg.Now == nil {
		cfg.Now = time.Now
	}
	if cfg.Logger == nil {
		cfg.Logger = log.Default()
	}
	if cfg.AuthDeadline <= 0 {
		cfg.AuthDeadline = defaultAuthDeadline
	}
	s := &Server{cfg: cfg, hub: NewHub(cfg.Limits, cfg.Now), origins: map[string]bool{}}
	for _, o := range cfg.AllowedOrigins {
		s.origins[o] = true
	}
	s.upgrader = websocket.Upgrader{
		ReadBufferSize:  4096,
		WriteBufferSize: 4096,
		// exact-match allowlist; a missing Origin header is refused (browsers always send it)
		CheckOrigin: func(r *http.Request) bool { return s.origins[r.Header.Get("Origin")] },
	}
	return s
}

func (s *Server) Hub() *Hub { return s.hub }

// PublicHandler serves /ws. The connection token is NOT accepted in the URL: it must arrive
// in the first message ({"type":"auth","token":"..."}) within AuthDeadline, so it never
// lands in proxy or access logs (spec: Realtime Protocol, option b).
func (s *Server) PublicHandler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /ws", s.serveWS)
	return mux
}

// InternalHandler serves the private-network API used by PHP.
func (s *Server) InternalHandler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("POST /internal/broadcast", s.serveBroadcast)
	mux.HandleFunc("GET /internal/health", func(w http.ResponseWriter, r *http.Request) {
		rooms, conns := s.hub.Stats()
		writeJSON(w, http.StatusOK, map[string]any{"ok": true, "rooms": rooms, "connections": conns})
	})
	return mux
}

type inbound struct {
	Type           string          `json:"type"`
	Token          string          `json:"token,omitempty"`
	SessionBlockID int64           `json:"session_block_id,omitempty"`
	Stroke         json.RawMessage `json:"stroke,omitempty"`
}

func (s *Server) serveWS(w http.ResponseWriter, r *http.Request) {
	conn, err := s.upgrader.Upgrade(w, r, nil)
	if err != nil {
		return // upgrader already wrote the error (e.g. 403 on bad Origin)
	}
	defer conn.Close()
	conn.SetReadLimit(s.cfg.Limits.MaxMessageBytes)

	// --- authentication: first message, bounded deadline ---
	_ = conn.SetReadDeadline(s.cfg.Now().Add(s.cfg.AuthDeadline))
	var first inbound
	if err := conn.ReadJSON(&first); err != nil {
		closeWith(conn, closeAuthTimeout, "authentication required")
		return
	}
	claims, err := VerifyToken(first.Token, s.cfg.TokenKey, s.cfg.Now())
	if first.Type != "auth" || err != nil {
		closeWith(conn, closeAuthFailed, "authentication failed")
		return
	}
	cl, err := s.hub.Register(claims)
	if err != nil {
		code := closeLimit
		if err == ErrSessionEnded {
			code = closeSessionEnded
		}
		closeWith(conn, code, err.Error())
		return
	}
	s.cfg.Logger.Printf("connect session=%d role=%s conn=%d", claims.SessionID, claims.Role, cl.ID)

	done := make(chan struct{})
	go s.writePump(conn, cl, done)
	s.readPump(conn, cl)
	s.hub.Unregister(cl)
	<-done
	s.cfg.Logger.Printf("disconnect session=%d role=%s conn=%d", claims.SessionID, claims.Role, cl.ID)
}

func (s *Server) readPump(conn *websocket.Conn, cl *Client) {
	_ = conn.SetReadDeadline(s.cfg.Now().Add(readTimeout))
	conn.SetPongHandler(func(string) error {
		return conn.SetReadDeadline(s.cfg.Now().Add(readTimeout))
	})
	for {
		var m inbound
		if err := conn.ReadJSON(&m); err != nil {
			return
		}
		_ = conn.SetReadDeadline(s.cfg.Now().Add(readTimeout))
		if !s.hub.Allow(cl) {
			s.hub.Send(cl, mustJSON(map[string]string{"type": "error", "code": "rate_limited"}))
			continue
		}
		switch m.Type {
		case "ping":
			s.hub.Send(cl, []byte(`{"type":"pong"}`))
		case "whiteboard_stroke_broadcast", "whiteboard_clear":
			if err := s.hub.PresenterStroke(cl, m.Type, m.SessionBlockID, m.Stroke); err != nil {
				s.hub.Send(cl, mustJSON(map[string]string{"type": "error", "code": "rejected"}))
			}
		default:
			// Navigation and activity input go through PHP over HTTP (authoritative);
			// the socket accepts nothing else from clients.
			s.hub.Send(cl, mustJSON(map[string]string{"type": "error", "code": "unsupported"}))
		}
	}
}

// writePump is the only writer for conn. It exits when the hub closes cl.Send.
func (s *Server) writePump(conn *websocket.Conn, cl *Client, done chan<- struct{}) {
	defer close(done)
	ticker := time.NewTicker(pingInterval)
	defer ticker.Stop()
	for {
		select {
		case msg, ok := <-cl.Send:
			_ = conn.SetWriteDeadline(s.cfg.Now().Add(writeTimeout))
			if !ok {
				_ = conn.WriteMessage(websocket.CloseMessage, websocket.FormatCloseMessage(websocket.CloseNormalClosure, ""))
				_ = conn.Close()
				return
			}
			if err := conn.WriteMessage(websocket.TextMessage, msg); err != nil {
				_ = conn.Close()
				s.drain(cl)
				return
			}
		case <-ticker.C:
			_ = conn.SetWriteDeadline(s.cfg.Now().Add(writeTimeout))
			if err := conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				_ = conn.Close()
				s.drain(cl)
				return
			}
		}
	}
}

// drain waits for the hub to close cl.Send after a write failure (readPump will error on
// the closed conn and trigger Unregister).
func (s *Server) drain(cl *Client) {
	for range cl.Send {
	}
}

type broadcastRequest struct {
	SessionID  int64           `json:"session_id"`
	TargetRole Role            `json:"target_role,omitempty"`
	Message    json.RawMessage `json:"message"`
}

func (s *Server) serveBroadcast(w http.ResponseWriter, r *http.Request) {
	if !s.authorizedInternal(r) {
		writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "unauthorized"})
		return
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, s.cfg.Limits.MaxMessageBytes+1))
	if err != nil || int64(len(body)) > s.cfg.Limits.MaxMessageBytes {
		writeJSON(w, http.StatusRequestEntityTooLarge, map[string]string{"error": "too large"})
		return
	}
	var req broadcastRequest
	if err := json.Unmarshal(body, &req); err != nil || req.SessionID <= 0 || len(req.Message) == 0 {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "bad request"})
		return
	}
	if req.TargetRole != "" && req.TargetRole != RoleTutor && req.TargetRole != RoleParticipant {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "bad target_role"})
		return
	}
	var head struct {
		Type string `json:"type"`
	}
	if json.Unmarshal(req.Message, &head) != nil || !InternalBroadcastTypes[head.Type] {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "unsupported message type"})
		return
	}
	// re-encode compactly so exactly one JSON value is forwarded
	var compact json.RawMessage
	_ = json.Unmarshal(req.Message, &compact)
	n := s.hub.BroadcastInternal(req.SessionID, head.Type, compact, req.TargetRole)
	writeJSON(w, http.StatusAccepted, map[string]any{"delivered": n})
}

// authorizedInternal compares the bearer secret in constant time (hashing first so the
// comparison does not leak the secret's length).
func (s *Server) authorizedInternal(r *http.Request) bool {
	auth := r.Header.Get("Authorization")
	given, ok := strings.CutPrefix(auth, "Bearer ")
	if !ok || s.cfg.InternalSecret == "" {
		return false
	}
	a := sha256.Sum256([]byte(given))
	b := sha256.Sum256([]byte(s.cfg.InternalSecret))
	return subtle.ConstantTimeCompare(a[:], b[:]) == 1
}

func closeWith(conn *websocket.Conn, code int, reason string) {
	_ = conn.WriteControl(websocket.CloseMessage, websocket.FormatCloseMessage(code, reason), time.Now().Add(time.Second))
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}
