<?php

declare(strict_types=1);

namespace Tutora\Whiteboard;

use PDO;
use Tutora\Activity\BlockNotOpen;
use Tutora\Participant\ParticipantContext;
use Tutora\Security\HmacToken;
use Tutora\Tenant\TenantDb;

/**
 * Mints short-lived sidecar tokens (aud=tutora-whiteboard). The sidecar derives the Yjs
 * document from the (sid, bid) claims and enforces permissions from `role`; `kind` and
 * `tags` constrain annotate boards.
 *
 * - participant on the *current* collaborative whiteboard / annotate block: role participant
 * - participant on a presenter-mode whiteboard: no sidecar token (presenter mode is relay-only)
 * - tutor on any whiteboard/annotate block of their own live session: role tutor
 */
final class WhiteboardService
{
    public const TOKEN_TTL = 60;

    public function __construct(private readonly PDO $pdo, private readonly HmacToken $tokens)
    {
    }

    /** @return array{token:string, role:string, kind:string}|null */
    public function participantToken(ParticipantContext $p, int $blockId): ?array
    {
        $s = $this->pdo->prepare(
            "SELECT s.status, s.current_session_block_id, b.block_type, b.config_snapshot
             FROM sessions s JOIN session_blocks b ON b.session_id = s.id WHERE s.id = ? AND b.id = ?"
        );
        $s->execute([$p->sessionId, $blockId]);
        $row = $s->fetch();
        if ($row === false || $row['status'] !== 'live' || (int) $row['current_session_block_id'] !== $blockId) {
            throw new BlockNotOpen();
        }
        $config = json_decode((string) $row['config_snapshot'], true, 64, JSON_THROW_ON_ERROR);
        if ($row['block_type'] === 'whiteboard' && ($config['mode'] ?? 'presenter') === 'presenter') {
            return null;
        }
        if (!in_array($row['block_type'], ['whiteboard', 'annotate'], true)) {
            throw new BlockNotOpen();
        }
        return $this->issue($p->sessionId, $blockId, $p->actorId, 'participant', $row['block_type'], $config);
    }

    /** @return array{token:string, role:string, kind:string}|null */
    public function tutorToken(TenantDb $tenant, int $sessionId, int $blockId): ?array
    {
        $row = $tenant->one(
            "SELECT b.block_type, b.config_snapshot FROM session_blocks b JOIN sessions s ON s.id = b.session_id
             WHERE s.id = :sid AND s.tenant_id = :tenant_id AND s.status = 'live' AND b.id = :bid
               AND b.block_type IN ('whiteboard', 'annotate')",
            ['sid' => $sessionId, 'bid' => $blockId],
        );
        if ($row === null) {
            return null;
        }
        $config = json_decode((string) $row['config_snapshot'], true, 64, JSON_THROW_ON_ERROR);
        return $this->issue($sessionId, $blockId, 'tutor', 'tutor', $row['block_type'], $config);
    }

    /**
     * @return array{token:string, role:string, kind:string}
     *
     * @param array<string,mixed> $config
     */
    private function issue(int $sid, int $bid, string $actor, string $role, string $kind, array $config): array
    {
        $tags = $kind === 'annotate' ? array_column($config['tags'] ?? [], 'id') : [];
        return [
            'token' => $this->tokens->issue(['sid' => $sid, 'bid' => $bid, 'actor' => $actor, 'role' => $role, 'kind' => $kind, 'tags' => $tags], self::TOKEN_TTL),
            'role' => $role,
            'kind' => $kind,
        ];
    }
}
