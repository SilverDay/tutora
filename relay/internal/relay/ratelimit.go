package relay

import "time"

// bucket is a token bucket. Buckets are kept per (session, actor) — shared by all of that
// actor's connections — so opening more tabs does not raise an actor's allowance
// (spec: rate limiting is computed globally per actor_id, never per socket).
type bucket struct {
	tokens float64
	last   time.Time
}

func (b *bucket) allow(now time.Time, ratePerSec, burst float64) bool {
	if b.last.IsZero() {
		b.tokens = burst
		b.last = now
	}
	elapsed := now.Sub(b.last).Seconds()
	if elapsed > 0 {
		b.tokens += elapsed * ratePerSec
		if b.tokens > burst {
			b.tokens = burst
		}
		b.last = now
	}
	if b.tokens < 1 {
		return false
	}
	b.tokens--
	return true
}
