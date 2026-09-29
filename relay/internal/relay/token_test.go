package relay

import (
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"
	"strings"
	"testing"
	"time"
)

type phpVectors struct {
	KeyHex             string `json:"key_hex"`
	NowUnix            int64  `json:"now_unix"`
	Participant        string `json:"participant"`
	Tutor              string `json:"tutor"`
	WhiteboardAudience string `json:"whiteboard_audience"`
}

// Tokens minted by the PHP app (Tutora\Security\HmacToken) must verify here unchanged.
func loadVectors(t *testing.T) (phpVectors, []byte, time.Time) {
	t.Helper()
	raw, err := os.ReadFile("testdata/php_tokens.json")
	if err != nil {
		t.Fatal(err)
	}
	var v phpVectors
	if err := json.Unmarshal(raw, &v); err != nil {
		t.Fatal(err)
	}
	key, _ := hex.DecodeString(v.KeyHex)
	return v, key, time.Unix(v.NowUnix, 0)
}

func TestVerifiesPHPMintedTokens(t *testing.T) {
	v, key, now := loadVectors(t)
	c, err := VerifyToken(v.Participant, key, now)
	if err != nil {
		t.Fatalf("participant token rejected: %v", err)
	}
	if c.SessionID != 42 || c.Role != RoleParticipant || c.Actor != "0123456789abcdef0123456789abcdef" || c.Iat != now.Unix() {
		t.Fatalf("unexpected claims %+v", c)
	}
	c, err = VerifyToken(v.Tutor, key, now)
	if err != nil || c.Role != RoleTutor {
		t.Fatalf("tutor token: %+v %v", c, err)
	}
}

func TestRejectsExpiredToken(t *testing.T) {
	v, key, now := loadVectors(t)
	if _, err := VerifyToken(v.Participant, key, now.Add(60*time.Second)); err == nil {
		t.Fatal("expired token accepted")
	}
}

func TestRejectsOtherAudience(t *testing.T) {
	v, key, now := loadVectors(t)
	if _, err := VerifyToken(v.WhiteboardAudience, key, now); err == nil {
		t.Fatal("whiteboard-audience token accepted by relay")
	}
}

func TestRejectsWrongKeyAndTampering(t *testing.T) {
	v, key, now := loadVectors(t)
	other := make([]byte, 32)
	if _, err := VerifyToken(v.Participant, other, now); err == nil {
		t.Fatal("wrong key accepted")
	}
	payload, sig, _ := strings.Cut(v.Participant, ".")
	raw, _ := base64.RawURLEncoding.DecodeString(payload)
	forged := strings.Replace(string(raw), `"role":"participant"`, `"role":"tutor"`, 1)
	if _, err := VerifyToken(base64.RawURLEncoding.EncodeToString([]byte(forged))+"."+sig, key, now); err == nil {
		t.Fatal("tampered payload accepted")
	}
	for _, bad := range []string{"", ".", "a.b", v.Participant + ".x", strings.Repeat("a", 5000)} {
		if _, err := VerifyToken(bad, key, now); err == nil {
			t.Fatalf("garbage accepted: %q", bad)
		}
	}
}

func TestRejectsInconsistentRoleAndActor(t *testing.T) {
	key := []byte(strings.Repeat("k", 32))
	now := time.Unix(1_700_000_000, 0)
	cases := []map[string]any{
		{"sid": 1, "actor": "tutor", "role": "participant"},
		{"sid": 1, "actor": "0123456789abcdef0123456789abcdef", "role": "tutor"},
		{"sid": 1, "actor": "x", "role": "admin"},
		{"sid": 0, "actor": "tutor", "role": "tutor"},
	}
	for _, c := range cases {
		c["aud"], c["exp"] = Audience, now.Unix()+60
		if _, err := VerifyToken(mint(key, c), key, now); err == nil {
			t.Fatalf("accepted %v", c)
		}
	}
}
