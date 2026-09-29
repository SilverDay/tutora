package relay

import (
	"encoding/json"
	"errors"
	"sync"
	"time"
)

// Limits bound resource use per room, actor and message.
type Limits struct {
	MaxConnsPerActor   int
	MaxConnsPerRoom    int
	MaxMessageBytes    int64
	SendQueue          int
	ActorRatePerSec    float64
	ActorBurst         float64
	MaxStrokesPerBlock int
	MaxStrokeBytes     int
}

// DefaultLimits are the v1 defaults (see docs/IMPLEMENTATION_PLAN.md §6).
func DefaultLimits() Limits {
	return Limits{
		MaxConnsPerActor:   10,
		MaxConnsPerRoom:    2000,
		MaxMessageBytes:    64 * 1024,
		SendQueue:          256,
		ActorRatePerSec:    30,
		ActorBurst:         60,
		MaxStrokesPerBlock: 5000,
		MaxStrokeBytes:     16 * 1024,
	}
}

var (
	ErrTooManyConnections = errors.New("too many connections")
	ErrRoomFull           = errors.New("room full")
	ErrSessionEnded       = errors.New("session ended")
	ErrRevoked            = errors.New("token revoked")
)

// endedTTL must exceed the connection-token lifetime (60 s) so a token minted just before
// the session ended cannot reopen the room.
const endedTTL = 5 * time.Minute

// Client is one physical connection. Clients are keyed by (actor, id): a participant with
// several tabs has several clients, and messages addressed to them fan out to all.
type Client struct {
	ID        uint64
	SessionID int64
	Actor     string
	Role      Role
	// Send is written only by the hub (under its lock) and closed by the hub on removal.
	Send chan []byte
}

type whiteboardBuffer struct {
	strokes [][]byte
	bytes   int
}

type room struct {
	clients map[string]map[uint64]*Client
	buckets map[string]*bucket
	// mirrored authoritative position (from PHP broadcasts); 0 = unknown
	currentBlock int64
	revision     int64
	whiteboards  map[int64]*whiteboardBuffer
}

// Hub holds all rooms (room = session_id). State is in memory only and is lost on
// restart; clients recover via HTTP state (fetch-then-subscribe), per the spec.
type Hub struct {
	mu    sync.Mutex
	rooms map[int64]*room
	ended map[int64]time.Time
	// revokedTutor: session -> time of the last tutor revocation (credentials changed);
	// tutor tokens issued at or before it are refused for endedTTL (> token lifetime).
	revokedTutor map[int64]time.Time
	nextID       uint64
	limits       Limits
	now          func() time.Time
}

func NewHub(limits Limits, now func() time.Time) *Hub {
	if now == nil {
		now = time.Now
	}
	return &Hub{rooms: map[int64]*room{}, ended: map[int64]time.Time{}, revokedTutor: map[int64]time.Time{}, limits: limits, now: now}
}

func (h *Hub) roomLocked(sid int64) *room {
	r := h.rooms[sid]
	if r == nil {
		r = &room{
			clients:     map[string]map[uint64]*Client{},
			buckets:     map[string]*bucket{},
			whiteboards: map[int64]*whiteboardBuffer{},
		}
		h.rooms[sid] = r
	}
	return r
}

// Register adds a connection for authenticated claims and returns its client plus the
// messages it should receive first (auth_ok and any presenter whiteboard backlog).
func (h *Hub) Register(c Claims) (*Client, error) {
	h.mu.Lock()
	defer h.mu.Unlock()
	now := h.now()
	for sid, at := range h.ended {
		if now.Sub(at) > endedTTL {
			delete(h.ended, sid)
		}
	}
	if _, ok := h.ended[c.SessionID]; ok {
		return nil, ErrSessionEnded
	}
	for sid, at := range h.revokedTutor {
		if now.Sub(at) > endedTTL {
			delete(h.revokedTutor, sid)
		}
	}
	if at, ok := h.revokedTutor[c.SessionID]; ok && c.Role == RoleTutor && c.Iat <= at.Unix() {
		return nil, ErrRevoked
	}
	r := h.roomLocked(c.SessionID)
	total := 0
	for _, conns := range r.clients {
		total += len(conns)
	}
	if total >= h.limits.MaxConnsPerRoom {
		return nil, ErrRoomFull
	}
	if len(r.clients[c.Actor]) >= h.limits.MaxConnsPerActor {
		return nil, ErrTooManyConnections
	}
	h.nextID++
	cl := &Client{ID: h.nextID, SessionID: c.SessionID, Actor: c.Actor, Role: c.Role, Send: make(chan []byte, h.limits.SendQueue)}
	if r.clients[c.Actor] == nil {
		r.clients[c.Actor] = map[uint64]*Client{}
	}
	r.clients[c.Actor][cl.ID] = cl

	h.enqueueLocked(r, cl, mustJSON(map[string]any{
		"type": "auth_ok", "connection_id": cl.ID, "session_revision": r.revision, "session_block_id": r.currentBlock,
	}))
	if wb := r.whiteboards[r.currentBlock]; wb != nil && r.currentBlock != 0 {
		for _, s := range wb.strokes {
			h.enqueueLocked(r, cl, s)
		}
	}
	h.presenceLocked(r)
	return cl, nil
}

// RevokeTutor disconnects every tutor connection of a session and refuses tutor tokens
// issued up to now (the tutor's credentials changed; PHP ended their other sessions).
// Returns the number of connections closed.
func (h *Hub) RevokeTutor(sid int64) int {
	h.mu.Lock()
	defer h.mu.Unlock()
	h.revokedTutor[sid] = h.now()
	r := h.rooms[sid]
	if r == nil {
		return 0
	}
	var targets []*Client
	for _, conns := range r.clients {
		for _, cl := range conns {
			if cl.Role == RoleTutor {
				targets = append(targets, cl)
			}
		}
	}
	for _, cl := range targets {
		h.removeLocked(r, cl)
	}
	if h.rooms[sid] != nil {
		h.presenceLocked(r)
	}
	return len(targets)
}

// Unregister removes a connection. Idempotent.
func (h *Hub) Unregister(cl *Client) {
	h.mu.Lock()
	defer h.mu.Unlock()
	r := h.rooms[cl.SessionID]
	if r == nil {
		return
	}
	h.removeLocked(r, cl)
	h.presenceLocked(r)
}

func (h *Hub) removeLocked(r *room, cl *Client) {
	conns := r.clients[cl.Actor]
	if _, ok := conns[cl.ID]; !ok {
		return
	}
	delete(conns, cl.ID)
	close(cl.Send)
	if len(conns) == 0 {
		delete(r.clients, cl.Actor)
		delete(r.buckets, cl.Actor)
	}
	if len(r.clients) == 0 {
		delete(h.rooms, cl.SessionID)
	}
}

// enqueueLocked is a non-blocking send; a client that cannot keep up is disconnected
// rather than allowed to stall the room.
func (h *Hub) enqueueLocked(r *room, cl *Client, msg []byte) {
	select {
	case cl.Send <- msg:
	default:
		h.removeLocked(r, cl)
	}
}

func (h *Hub) fanoutLocked(r *room, msg []byte, target Role) int {
	var targets []*Client
	for _, conns := range r.clients {
		for _, cl := range conns {
			if target == "" || cl.Role == target {
				targets = append(targets, cl)
			}
		}
	}
	for _, cl := range targets {
		h.enqueueLocked(r, cl, msg)
	}
	return len(targets)
}

// presenceLocked tells tutors how many distinct participants are connected (a count only).
func (h *Hub) presenceLocked(r *room) {
	n := 0
	for _, conns := range r.clients {
		for _, cl := range conns {
			if cl.Role == RoleParticipant {
				n++
				break
			}
		}
	}
	h.fanoutLocked(r, mustJSON(map[string]any{"type": "presence", "participants": n}), RoleTutor)
}

// Allow applies the per-actor rate limit shared across that actor's connections.
func (h *Hub) Allow(cl *Client) bool {
	h.mu.Lock()
	defer h.mu.Unlock()
	r := h.rooms[cl.SessionID]
	if r == nil {
		return false
	}
	b := r.buckets[cl.Actor]
	if b == nil {
		b = &bucket{}
		r.buckets[cl.Actor] = b
	}
	return b.allow(h.now(), h.limits.ActorRatePerSec, h.limits.ActorBurst)
}

// Send delivers a message to one connection (e.g. pong, error).
func (h *Hub) Send(cl *Client, msg []byte) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if r := h.rooms[cl.SessionID]; r != nil {
		if _, ok := r.clients[cl.Actor][cl.ID]; ok {
			h.enqueueLocked(r, cl, msg)
		}
	}
}

// InternalBroadcastTypes are the message types PHP may push through /internal/broadcast.
var InternalBroadcastTypes = map[string]bool{
	"block_change":              true,
	"activity_aggregate_update": true,
	"quiz_question_start":       true,
	"quiz_question_reveal":      true,
	"wall_update":               true,
	"capture":                   true,
	"session_ended":             true,
}

// BroadcastInternal fans out a PHP-originated message and updates the mirrored state.
// Returns the number of connections it was queued for.
func (h *Hub) BroadcastInternal(sid int64, msgType string, raw []byte, target Role) int {
	h.mu.Lock()
	defer h.mu.Unlock()
	if msgType == "session_ended" {
		h.ended[sid] = h.now()
	}
	r := h.rooms[sid]
	if r == nil {
		return 0 // nobody connected; clients fetch state over HTTP when they connect
	}
	if msgType == "block_change" {
		var m struct {
			Rev   int64 `json:"session_revision"`
			Block int64 `json:"session_block_id"`
		}
		if json.Unmarshal(raw, &m) == nil && m.Rev > r.revision {
			r.revision, r.currentBlock = m.Rev, m.Block
		}
	}
	n := h.fanoutLocked(r, raw, target)
	if msgType == "session_ended" {
		for _, conns := range r.clients {
			for _, cl := range conns {
				h.removeLocked(r, cl)
			}
		}
	}
	return n
}

// PresenterStroke handles a tutor's whiteboard_stroke_broadcast / whiteboard_clear:
// buffers it as latest state for late joiners and fans it out to the room. The message is
// re-serialised with known keys only; the stroke body is opaque JSON rendered on a canvas.
func (h *Hub) PresenterStroke(cl *Client, msgType string, blockID int64, stroke json.RawMessage) error {
	if cl.Role != RoleTutor {
		return errors.New("forbidden")
	}
	if len(stroke) > h.limits.MaxStrokeBytes {
		return errors.New("too large")
	}
	out := map[string]any{"type": msgType, "session_block_id": blockID}
	if msgType != "whiteboard_clear" {
		if len(stroke) == 0 || !json.Valid(stroke) {
			return errors.New("invalid stroke")
		}
		out["stroke"] = stroke
	}
	raw := mustJSON(out)
	h.mu.Lock()
	defer h.mu.Unlock()
	r := h.rooms[cl.SessionID]
	if r == nil {
		return errors.New("no room")
	}
	if blockID <= 0 || (r.currentBlock != 0 && blockID != r.currentBlock) {
		return errors.New("not the current block")
	}
	wb := r.whiteboards[blockID]
	if wb == nil {
		wb = &whiteboardBuffer{}
		r.whiteboards[blockID] = wb
	}
	if msgType == "whiteboard_clear" {
		wb.strokes, wb.bytes = nil, 0
	} else {
		if len(wb.strokes) >= h.limits.MaxStrokesPerBlock {
			return errors.New("whiteboard full")
		}
		wb.strokes = append(wb.strokes, raw)
		wb.bytes += len(raw)
	}
	for _, conns := range r.clients {
		for _, other := range conns {
			if other != cl {
				h.enqueueLocked(r, other, raw)
			}
		}
	}
	return nil
}

// Stats returns (rooms, connections) for health output.
func (h *Hub) Stats() (int, int) {
	h.mu.Lock()
	defer h.mu.Unlock()
	n := 0
	for _, r := range h.rooms {
		for _, conns := range r.clients {
			n += len(conns)
		}
	}
	return len(h.rooms), n
}

func mustJSON(v any) []byte {
	b, err := json.Marshal(v)
	if err != nil {
		panic(err)
	}
	return b
}
