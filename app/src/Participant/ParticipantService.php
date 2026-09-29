<?php

declare(strict_types=1);

namespace Tutora\Participant;

use DateTimeImmutable;
use PDO;
use Tutora\Block\BlockConfig;
use Tutora\Block\BlockType;
use Tutora\Database\Transaction;
use Tutora\Security\Base64Url;
use Tutora\Security\HmacToken;
use Tutora\Security\RateLimiter;
use Tutora\Security\RateLimitPolicy;
use Tutora\Session\JoinCode;
use Tutora\Session\SessionService;
use Tutora\Support\Clock;
use Tutora\Support\Limits;
use Tutora\Support\Time;

/**
 * Anonymous, session-scoped participation (spec: Session/Block Data Model, Auth).
 *
 * - join: short code -> participant row with an opaque moderation_actor_id
 * - participant credential: HMAC token (aud=tutora-participant) bound to session + participant
 * - resume token: 256-bit CSPRNG, stored only as SHA-256, rotated on every use
 * - resume expiry: min(max(last_activity_at, presence_renewed_at) + 30 min, session_end + 15 min)
 */
final class ParticipantService
{
    public const CREDENTIAL_TTL = 1200;
    public const RESUME_WINDOW = 1800;
    public const CONNECTION_TOKEN_TTL = 60;

    public const JOIN_BUDGET_PER_IP = 30;
    public const JOIN_BUDGET_WINDOW = 600;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly HmacToken $credentials,
        private readonly HmacToken $relayTokens,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * @return array{session_id:int, credential:string, resume_token:string, expires_in:int}|JoinFailure
     */
    public function join(string $codeInput, ?string $displayName, string $ip): array|JoinFailure
    {
        $wait = $this->limiter->retryAfter('join-fail-ip:' . $ip);
        if ($wait > 0 || !$this->limiter->consume('join-ip:' . $ip, self::JOIN_BUDGET_PER_IP, self::JOIN_BUDGET_WINDOW)) {
            return JoinFailure::throttled(max($wait, 60));
        }
        $name = null;
        if ($displayName !== null && trim($displayName) !== '') {
            $name = trim($displayName);
            if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > Limits::DISPLAY_NAME || preg_match('/\p{Cc}/u', $name) === 1) {
                return JoinFailure::invalid(sprintf('Display name must be at most %d characters.', Limits::DISPLAY_NAME));
            }
        }
        $code = JoinCode::normalize($codeInput);
        $session = null;
        if ($code !== null) {
            $s = $this->pdo->prepare("SELECT id FROM sessions WHERE active_join_code = ? AND status = 'live'");
            $s->execute([$code]);
            $session = $s->fetchColumn();
        }
        if ($code === null || $session === false) {
            // wrong codes feed a per-IP backoff: guessing codes gets progressively slower
            $this->limiter->recordFailure('join-fail-ip:' . $ip, new RateLimitPolicy(10, 1, 300, 3600));
            return JoinFailure::invalid('No live session found for this code.');
        }
        $sessionId = (int) $session;
        $now = $this->clock->now();
        $actor = random_bytes(16);
        $resume = Base64Url::encode(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO session_participants (session_id, moderation_actor_id, display_name, joined_at, last_activity_at,
                presence_renewed_at, resume_token_hash, resume_token_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $sessionId, $actor, $name, Time::toDb($now), Time::toDb($now), Time::toDb($now),
            hash('sha256', $resume, true), Time::toDb($now->modify('+' . self::RESUME_WINDOW . ' seconds')),
        ]);
        $participantId = (int) $this->pdo->lastInsertId();
        return [
            'session_id' => $sessionId,
            'credential' => $this->issueCredential($sessionId, $participantId, bin2hex($actor)),
            'resume_token' => $resume,
            'expires_in' => self::CREDENTIAL_TTL,
        ];
    }

    /**
     * Exchanges a resume token for a fresh credential and a rotated resume token.
     *
     * @return array{session_id:int, credential:string, resume_token:string, expires_in:int}|null
     */
    public function resume(string $resumeToken, string $ip): ?array
    {
        if ($this->limiter->retryAfter('resume-fail-ip:' . $ip) > 0 || strlen($resumeToken) > 100) {
            return null;
        }
        $now = $this->clock->now();
        $result = Transaction::run($this->pdo, function (PDO $pdo) use ($resumeToken, $now): ?array {
            $s = $pdo->prepare(
                "SELECT p.id, p.session_id, p.moderation_actor_id FROM session_participants p
                 JOIN sessions s ON s.id = p.session_id
                 WHERE p.resume_token_hash = ? AND p.resume_token_expires_at > ? AND s.status = 'live' FOR UPDATE"
            );
            $s->execute([hash('sha256', $resumeToken, true), Time::toDb($now)]);
            $row = $s->fetch();
            if ($row === false) {
                return null;
            }
            $new = Base64Url::encode(random_bytes(32));
            $pdo->prepare('UPDATE session_participants SET resume_token_hash = ? WHERE id = ?')
                ->execute([hash('sha256', $new, true), (int) $row['id']]);
            return [
                'session_id' => (int) $row['session_id'],
                'credential' => $this->issueCredential((int) $row['session_id'], (int) $row['id'], bin2hex($row['moderation_actor_id'])),
                'resume_token' => $new,
                'expires_in' => self::CREDENTIAL_TTL,
            ];
        });
        if ($result === null) {
            $this->limiter->recordFailure('resume-fail-ip:' . $ip, new RateLimitPolicy(20, 1, 300, 3600));
        }
        return $result;
    }

    /**
     * Verifies a participant credential for the session named in the request path.
     * A bare session id is never authorization: the credential's session claim must match.
     */
    public function authenticate(?string $credential, int $requestedSessionId): ?ParticipantContext
    {
        if ($credential === null) {
            return null;
        }
        $claims = $this->credentials->verify($credential);
        if ($claims === null || ($claims['sid'] ?? null) !== $requestedSessionId || !is_int($claims['pid'] ?? null) || !is_string($claims['act'] ?? null)) {
            return null;
        }
        $s = $this->pdo->prepare(
            "SELECT p.id FROM session_participants p JOIN sessions s ON s.id = p.session_id
             WHERE p.id = ? AND p.session_id = ? AND p.moderation_actor_id = ? AND s.status = 'live'"
        );
        $actorBin = @hex2bin($claims['act']);
        if ($actorBin === false || strlen($actorBin) !== 16) {
            return null;
        }
        $s->execute([$claims['pid'], $requestedSessionId, $actorBin]);
        return $s->fetchColumn() === false ? null : new ParticipantContext($requestedSessionId, $claims['pid'], $claims['act']);
    }

    /**
     * Foreground presence renewal (client calls ~every 15 min only while the tab is visible).
     * Extends the resume window and returns a fresh credential.
     *
     * @return array{credential:string, expires_in:int}
     */
    public function renewPresence(ParticipantContext $p): array
    {
        $now = $this->clock->now();
        $this->pdo->prepare(
            'UPDATE session_participants SET presence_renewed_at = ?, resume_token_expires_at = ? WHERE id = ? AND session_id = ?'
        )->execute([Time::toDb($now), Time::toDb(self::resumeExpiry($now, $now, null)), $p->participantId, $p->sessionId]);
        return ['credential' => $this->issueCredential($p->sessionId, $p->participantId, $p->actorId), 'expires_in' => self::CREDENTIAL_TTL];
    }

    /** Called on every meaningful interaction (a submission). */
    public function recordActivity(ParticipantContext $p): void
    {
        $now = $this->clock->now();
        $this->pdo->prepare(
            'UPDATE session_participants SET last_activity_at = ?, resume_token_expires_at = ? WHERE id = ? AND session_id = ?'
        )->execute([Time::toDb($now), Time::toDb(self::resumeExpiry($now, $now, null)), $p->participantId, $p->sessionId]);
    }

    /** min(max(last_activity_at, presence_renewed_at) + 30 min, session_end + 15 min) */
    public static function resumeExpiry(DateTimeImmutable $lastActivity, DateTimeImmutable $presence, ?DateTimeImmutable $sessionEnd): DateTimeImmutable
    {
        $base = max($lastActivity, $presence)->modify('+' . self::RESUME_WINDOW . ' seconds');
        if ($sessionEnd === null) {
            return $base;
        }
        return min($base, $sessionEnd->modify('+' . SessionService::RESUME_GRACE_AFTER_END . ' seconds'));
    }

    /**
     * Short-lived relay connection token (spec: Realtime Protocol, ~60 s, HMAC, no DB on relay side).
     */
    public function connectionToken(ParticipantContext $p): string
    {
        return $this->relayTokens->issue(['sid' => $p->sessionId, 'actor' => $p->actorId, 'role' => 'participant'], self::CONNECTION_TOKEN_TTL);
    }

    /**
     * Participant-facing authoritative state (fetch-then-subscribe). Uses the stripped
     * participant projection of the current block's config.
     *
     * @return array<string,mixed>
     */
    public function state(ParticipantContext $p): array
    {
        $s = $this->pdo->prepare(
            'SELECT s.status, s.session_revision, s.current_session_block_id, s.workshop_title_snapshot,
                    b.id AS block_id, b.position, b.block_type, b.config_snapshot, b.slide_asset_id
             FROM sessions s LEFT JOIN session_blocks b ON b.id = s.current_session_block_id AND b.session_id = s.id
             WHERE s.id = ?'
        );
        $s->execute([$p->sessionId]);
        $row = $s->fetch();
        $block = null;
        if ($row !== false && $row['block_id'] !== null) {
            $type = BlockType::from($row['block_type']);
            $block = [
                'id' => (int) $row['block_id'],
                'position' => (int) $row['position'],
                'type' => $type->value,
                'config' => BlockConfig::participantView($type, json_decode((string) $row['config_snapshot'], true, 64, JSON_THROW_ON_ERROR)),
                'slide_asset_id' => $row['slide_asset_id'] === null ? null : (int) $row['slide_asset_id'],
            ];
        }
        return [
            'session_id' => $p->sessionId,
            'title' => $row === false ? null : $row['workshop_title_snapshot'],
            'status' => $row === false ? 'ended' : $row['status'],
            'session_revision' => $row === false ? 0 : (int) $row['session_revision'],
            'current_session_block_id' => $block['id'] ?? null,
            'current_block' => $block,
            'actor_id' => $p->actorId,
        ];
    }

    private function issueCredential(int $sessionId, int $participantId, string $actorHex): string
    {
        return $this->credentials->issue(['sid' => $sessionId, 'pid' => $participantId, 'act' => $actorHex], self::CREDENTIAL_TTL);
    }
}
