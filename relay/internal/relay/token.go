package relay

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"regexp"
	"strings"
	"time"
)

// Audience is the aud claim the PHP app sets on relay connection tokens.
const Audience = "tutora-relay"

// Role of an authenticated connection.
type Role string

const (
	RoleTutor       Role = "tutor"
	RoleParticipant Role = "participant"
)

// Claims of a connection token minted by PHP (Tutora\Security\HmacToken, aud=tutora-relay).
type Claims struct {
	SessionID int64  `json:"sid"`
	Actor     string `json:"actor"`
	Role      Role   `json:"role"`
	Aud       string `json:"aud"`
	Exp       int64  `json:"exp"`
}

var (
	ErrInvalidToken = errors.New("invalid token")
	participantID   = regexp.MustCompile(`^[0-9a-f]{32}$`)
)

// VerifyToken checks format, HMAC-SHA256 signature (constant time), audience, expiry and
// claim shape. The format is base64url(json claims) "." base64url(hmac), unpadded.
// No database access is needed: the token is self-verifying.
func VerifyToken(token string, key []byte, now time.Time) (Claims, error) {
	var c Claims
	if len(token) == 0 || len(token) > 4096 {
		return c, ErrInvalidToken
	}
	payload, sig, ok := strings.Cut(token, ".")
	if !ok || strings.Contains(sig, ".") {
		return c, ErrInvalidToken
	}
	given, err := base64.RawURLEncoding.DecodeString(sig)
	if err != nil {
		return c, ErrInvalidToken
	}
	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(payload))
	if !hmac.Equal(mac.Sum(nil), given) {
		return c, ErrInvalidToken
	}
	raw, err := base64.RawURLEncoding.DecodeString(payload)
	if err != nil {
		return c, ErrInvalidToken
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	if err := dec.Decode(&c); err != nil {
		return Claims{}, ErrInvalidToken
	}
	switch {
	case c.Aud != Audience,
		c.Exp <= now.Unix(),
		c.SessionID <= 0,
		c.Role == RoleTutor && c.Actor != "tutor",
		c.Role == RoleParticipant && !participantID.MatchString(c.Actor),
		c.Role != RoleTutor && c.Role != RoleParticipant:
		return Claims{}, ErrInvalidToken
	}
	return c, nil
}
