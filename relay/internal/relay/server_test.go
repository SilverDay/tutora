package relay

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

const (
	testOrigin = "https://tutora.test"
	testSecret = "internal-secret-internal-secret-0123"
	actorA     = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
	actorB     = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
)

var testKey = []byte(strings.Repeat("\x11", 32))

type env struct {
	t        *testing.T
	srv      *Server
	public   *httptest.Server
	internal *httptest.Server
}

func newEnv(t *testing.T, mod func(*Config)) *env {
	t.Helper()
	cfg := Config{TokenKey: testKey, InternalSecret: testSecret, AllowedOrigins: []string{testOrigin}, Limits: DefaultLimits(), AuthDeadline: 300 * time.Millisecond}
	cfg.Logger = nil
	if mod != nil {
		mod(&cfg)
	}
	s := NewServer(cfg)
	e := &env{t: t, srv: s, public: httptest.NewServer(s.PublicHandler()), internal: httptest.NewServer(s.InternalHandler())}
	t.Cleanup(func() { e.public.Close(); e.internal.Close() })
	return e
}

func token(sid int64, actor string, role Role) string {
	return mint(testKey, map[string]any{"sid": sid, "actor": actor, "role": string(role), "aud": Audience, "exp": time.Now().Unix() + 60})
}

func (e *env) dial(origin string, query string) (*websocket.Conn, *http.Response, error) {
	url := "ws" + strings.TrimPrefix(e.public.URL, "http") + "/ws" + query
	h := http.Header{}
	if origin != "" {
		h.Set("Origin", origin)
	}
	return websocket.DefaultDialer.Dial(url, h)
}

// connect dials and authenticates, returning the conn after reading auth_ok.
func (e *env) connect(sid int64, actor string, role Role) *websocket.Conn {
	e.t.Helper()
	c, _, err := e.dial(testOrigin, "")
	if err != nil {
		e.t.Fatal(err)
	}
	e.t.Cleanup(func() { c.Close() })
	if err := c.WriteJSON(map[string]string{"type": "auth", "token": token(sid, actor, role)}); err != nil {
		e.t.Fatal(err)
	}
	m := read(e.t, c)
	if m["type"] != "auth_ok" {
		e.t.Fatalf("expected auth_ok, got %v", m)
	}
	return c
}

func read(t *testing.T, c *websocket.Conn) map[string]any {
	t.Helper()
	_ = c.SetReadDeadline(time.Now().Add(2 * time.Second))
	var m map[string]any
	if err := c.ReadJSON(&m); err != nil {
		t.Fatalf("read: %v", err)
	}
	return m
}

// readType skips messages (e.g. presence) until one of the wanted type arrives.
func readType(t *testing.T, c *websocket.Conn, typ string) map[string]any {
	t.Helper()
	for i := 0; i < 20; i++ {
		if m := read(t, c); m["type"] == typ {
			return m
		}
	}
	t.Fatalf("no %s message", typ)
	return nil
}

func closeCode(t *testing.T, c *websocket.Conn) int {
	t.Helper()
	_ = c.SetReadDeadline(time.Now().Add(2 * time.Second))
	for {
		_, _, err := c.ReadMessage()
		if err != nil {
			if ce, ok := err.(*websocket.CloseError); ok {
				return ce.Code
			}
			return -1
		}
	}
}

func (e *env) broadcast(secret string, body any) (int, map[string]any) {
	raw, _ := json.Marshal(body)
	req, _ := http.NewRequest("POST", e.internal.URL+"/internal/broadcast", bytes.NewReader(raw))
	if secret != "" {
		req.Header.Set("Authorization", "Bearer "+secret)
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		e.t.Fatal(err)
	}
	defer resp.Body.Close()
	var out map[string]any
	b, _ := io.ReadAll(resp.Body)
	_ = json.Unmarshal(b, &out)
	return resp.StatusCode, out
}

func TestForeignOrMissingOriginRefused(t *testing.T) {
	e := newEnv(t, nil)
	for _, o := range []string{"https://evil.example", ""} {
		_, resp, err := e.dial(o, "")
		if err == nil || resp == nil || resp.StatusCode != http.StatusForbidden {
			t.Fatalf("origin %q not refused", o)
		}
	}
}

func TestAuthRequiredWithinDeadline(t *testing.T) {
	e := newEnv(t, nil)
	c, _, err := e.dial(testOrigin, "")
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	if code := closeCode(t, c); code != closeAuthTimeout {
		t.Fatalf("expected %d, got %d", closeAuthTimeout, code)
	}
}

func TestTokenInQueryStringIsIgnored(t *testing.T) {
	e := newEnv(t, nil)
	c, _, err := e.dial(testOrigin, "?token="+token(1, actorA, RoleParticipant))
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	if code := closeCode(t, c); code != closeAuthTimeout {
		t.Fatalf("URL token must not authenticate; got close %d", code)
	}
}

func TestBadTokenRejected(t *testing.T) {
	e := newEnv(t, nil)
	c, _, _ := e.dial(testOrigin, "")
	defer c.Close()
	_ = c.WriteJSON(map[string]string{"type": "auth", "token": "nope"})
	if code := closeCode(t, c); code != closeAuthFailed {
		t.Fatalf("expected %d, got %d", closeAuthFailed, code)
	}
}

func TestInternalBroadcastRequiresSecretAndKnownType(t *testing.T) {
	e := newEnv(t, nil)
	msg := map[string]any{"session_id": 1, "message": map[string]any{"type": "block_change", "session_revision": 2, "session_block_id": 9}}
	if code, _ := e.broadcast("", msg); code != http.StatusUnauthorized {
		t.Fatalf("missing secret: %d", code)
	}
	if code, _ := e.broadcast("wrong", msg); code != http.StatusUnauthorized {
		t.Fatalf("wrong secret: %d", code)
	}
	if code, _ := e.broadcast(testSecret, map[string]any{"session_id": 1, "message": map[string]any{"type": "auth_ok"}}); code != http.StatusBadRequest {
		t.Fatalf("unsupported type accepted: %d", code)
	}
	if code, _ := e.broadcast(testSecret, map[string]any{"session_id": 1, "target_role": "admin", "message": map[string]any{"type": "capture"}}); code != http.StatusBadRequest {
		t.Fatalf("bad target role accepted: %d", code)
	}
	if code, out := e.broadcast(testSecret, msg); code != http.StatusAccepted || out["delivered"].(float64) != 0 {
		t.Fatalf("valid broadcast: %d %v", code, out)
	}
}

func TestBlockChangeFansOutToEveryConnectionOfEveryActor(t *testing.T) {
	e := newEnv(t, nil)
	tutor := e.connect(1, "tutor", RoleTutor)
	a1 := e.connect(1, actorA, RoleParticipant)
	a2 := e.connect(1, actorA, RoleParticipant) // second tab of the same participant
	b := e.connect(1, actorB, RoleParticipant)
	other := e.connect(2, actorB, RoleParticipant) // another session

	code, out := e.broadcast(testSecret, map[string]any{"session_id": 1, "message": map[string]any{"type": "block_change", "session_revision": 5, "session_block_id": 77}})
	if code != http.StatusAccepted || out["delivered"].(float64) != 4 {
		t.Fatalf("delivered %v", out)
	}
	for _, c := range []*websocket.Conn{tutor, a1, a2, b} {
		m := readType(t, c, "block_change")
		if m["session_revision"].(float64) != 5 || m["session_block_id"].(float64) != 77 {
			t.Fatalf("bad message %v", m)
		}
	}
	_ = other.SetReadDeadline(time.Now().Add(200 * time.Millisecond))
	if _, _, err := other.ReadMessage(); err == nil {
		t.Fatal("other session received a message")
	}

	// new connections learn the mirrored revision in auth_ok
	c, _, _ := e.dial(testOrigin, "")
	defer c.Close()
	_ = c.WriteJSON(map[string]string{"type": "auth", "token": token(1, actorB, RoleParticipant)})
	if m := read(t, c); m["session_revision"].(float64) != 5 || m["session_block_id"].(float64) != 77 {
		t.Fatalf("auth_ok mirror %v", m)
	}
}

func TestTargetRole(t *testing.T) {
	e := newEnv(t, nil)
	tutor := e.connect(1, "tutor", RoleTutor)
	p := e.connect(1, actorA, RoleParticipant)
	_, out := e.broadcast(testSecret, map[string]any{"session_id": 1, "target_role": "tutor", "message": map[string]any{"type": "capture", "session_block_id": 3}})
	if out["delivered"].(float64) != 1 {
		t.Fatalf("delivered %v", out)
	}
	readType(t, tutor, "capture")
	_ = p.SetReadDeadline(time.Now().Add(200 * time.Millisecond))
	for {
		var m map[string]any
		if err := p.ReadJSON(&m); err != nil {
			break
		}
		if m["type"] == "capture" {
			t.Fatal("participant received tutor-only message")
		}
	}
}

func TestPresenterWhiteboard(t *testing.T) {
	e := newEnv(t, nil)
	tutor := e.connect(1, "tutor", RoleTutor)
	p := e.connect(1, actorA, RoleParticipant)
	e.broadcast(testSecret, map[string]any{"session_id": 1, "message": map[string]any{"type": "block_change", "session_revision": 2, "session_block_id": 10}})
	readType(t, p, "block_change")

	// participants may not draw in presenter mode
	_ = p.WriteJSON(map[string]any{"type": "whiteboard_stroke_broadcast", "session_block_id": 10, "stroke": map[string]any{"d": "M0 0"}})
	if m := readType(t, p, "error"); m["code"] != "rejected" {
		t.Fatalf("participant stroke not rejected: %v", m)
	}

	// strokes for a block that is not current are rejected
	_ = tutor.WriteJSON(map[string]any{"type": "whiteboard_stroke_broadcast", "session_block_id": 11, "stroke": map[string]any{"d": "M0 0"}})
	readType(t, tutor, "error")

	_ = tutor.WriteJSON(map[string]any{"type": "whiteboard_stroke_broadcast", "session_block_id": 10, "stroke": map[string]any{"d": "M0 0 L5 5"}, "extra": "<script>"})
	m := readType(t, p, "whiteboard_stroke_broadcast")
	if _, ok := m["extra"]; ok {
		t.Fatal("unknown keys forwarded")
	}
	if m["stroke"].(map[string]any)["d"] != "M0 0 L5 5" {
		t.Fatalf("stroke %v", m)
	}

	// late joiner receives the buffered latest state
	late := e.connect(1, actorB, RoleParticipant)
	readType(t, late, "whiteboard_stroke_broadcast")

	_ = tutor.WriteJSON(map[string]any{"type": "whiteboard_clear", "session_block_id": 10})
	readType(t, p, "whiteboard_clear")
	late2 := e.connect(1, "cccccccccccccccccccccccccccccccc", RoleParticipant)
	_ = late2.WriteJSON(map[string]string{"type": "ping"})
	for {
		m := read(t, late2)
		if m["type"] == "whiteboard_stroke_broadcast" {
			t.Fatal("cleared strokes replayed")
		}
		if m["type"] == "pong" {
			break
		}
	}
}

func TestRateLimitIsPerActorAcrossConnections(t *testing.T) {
	e := newEnv(t, func(c *Config) { c.Limits.ActorBurst = 4; c.Limits.ActorRatePerSec = 0.001 })
	a1 := e.connect(1, actorA, RoleParticipant)
	a2 := e.connect(1, actorA, RoleParticipant)
	b := e.connect(1, actorB, RoleParticipant)
	for i := 0; i < 2; i++ {
		_ = a1.WriteJSON(map[string]string{"type": "ping"})
		readType(t, a1, "pong")
		_ = a2.WriteJSON(map[string]string{"type": "ping"})
		readType(t, a2, "pong")
	}
	// the actor's shared budget (4) is spent; a second tab does not get a fresh one
	_ = a2.WriteJSON(map[string]string{"type": "ping"})
	if m := readType(t, a2, "error"); m["code"] != "rate_limited" {
		t.Fatalf("expected rate_limited, got %v", m)
	}
	// another actor is unaffected
	_ = b.WriteJSON(map[string]string{"type": "ping"})
	readType(t, b, "pong")
}

func TestConnectionCapPerActor(t *testing.T) {
	e := newEnv(t, func(c *Config) { c.Limits.MaxConnsPerActor = 2 })
	e.connect(1, actorA, RoleParticipant)
	e.connect(1, actorA, RoleParticipant)
	c, _, _ := e.dial(testOrigin, "")
	defer c.Close()
	_ = c.WriteJSON(map[string]string{"type": "auth", "token": token(1, actorA, RoleParticipant)})
	if code := closeCode(t, c); code != closeLimit {
		t.Fatalf("expected %d, got %d", closeLimit, code)
	}
}

func TestSessionEndedClosesAndBlocksReconnect(t *testing.T) {
	e := newEnv(t, nil)
	p := e.connect(1, actorA, RoleParticipant)
	e.broadcast(testSecret, map[string]any{"session_id": 1, "message": map[string]any{"type": "session_ended", "session_revision": 9}})
	readType(t, p, "session_ended")
	if code := closeCode(t, p); code != websocket.CloseNormalClosure {
		t.Fatalf("expected normal close, got %d", code)
	}
	c, _, _ := e.dial(testOrigin, "")
	defer c.Close()
	_ = c.WriteJSON(map[string]string{"type": "auth", "token": token(1, actorA, RoleParticipant)})
	if code := closeCode(t, c); code != closeSessionEnded {
		t.Fatalf("expected %d, got %d", closeSessionEnded, code)
	}
}

func TestPresenceCountForTutor(t *testing.T) {
	e := newEnv(t, nil)
	tutor := e.connect(1, "tutor", RoleTutor)
	e.connect(1, actorA, RoleParticipant)
	e.connect(1, actorA, RoleParticipant)
	e.connect(1, actorB, RoleParticipant)
	var last float64
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) && last != 2 {
		last = readType(t, tutor, "presence")["participants"].(float64)
	}
	if last != 2 {
		t.Fatalf("expected 2 distinct participants, got %v", last)
	}
}

func TestParticipantCannotSendOtherMessages(t *testing.T) {
	e := newEnv(t, nil)
	p := e.connect(1, actorA, RoleParticipant)
	_ = p.WriteJSON(map[string]any{"type": "block_change", "session_block_id": 5})
	if m := readType(t, p, "error"); m["code"] != "unsupported" {
		t.Fatalf("got %v", m)
	}
}

func TestOversizedMessageClosesConnection(t *testing.T) {
	e := newEnv(t, func(c *Config) { c.Limits.MaxMessageBytes = 1024 })
	p := e.connect(1, actorA, RoleParticipant)
	_ = p.WriteMessage(websocket.TextMessage, []byte(`{"type":"ping","pad":"`+strings.Repeat("x", 2048)+`"}`))
	if code := closeCode(t, p); code != websocket.CloseMessageTooBig {
		t.Fatalf("expected %d, got %d", websocket.CloseMessageTooBig, code)
	}
}
