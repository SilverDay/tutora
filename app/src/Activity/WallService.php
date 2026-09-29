<?php

declare(strict_types=1);

namespace Tutora\Activity;

use PDO;
use Tutora\Database\Transaction;
use Tutora\Participant\ParticipantContext;
use Tutora\Participant\ParticipantService;
use Tutora\Realtime\Broadcaster;
use Tutora\Support\Clock;
use Tutora\Support\Limits;
use Tutora\Support\Text;
use Tutora\Support\Time;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantDb;

/**
 * Wall cards (spec: Wall — the structural outlier). Moves are last-write-wins persisted in
 * PHP; the relay only carries a "wall_update" hint and clients re-fetch the card list.
 *
 * Participants may edit/move/delete only their own cards (decision: see plan); the tutor
 * may moderate any card. Authorship is never shown to participants.
 */
final class WallService
{
    public const MAX_CARDS_PER_PARTICIPANT = 20;
    /** moderation actor id used for tutor-created cards */
    public const TUTOR_ACTOR = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly Broadcaster $broadcaster,
        private readonly ParticipantService $participants,
    ) {
    }

    /** @throws ValidationException|BlockNotOpen */
    public function addCard(ParticipantContext $p, int $blockId, mixed $text, mixed $columnId): int
    {
        $id = Transaction::run($this->pdo, function (PDO $pdo) use ($p, $blockId, $text, $columnId): int {
            $config = $this->lockOpenWall($pdo, $p->sessionId, $blockId, true);
            [$text, $columnId] = self::validateCard($config, $text, $columnId);
            $c = $pdo->prepare('SELECT COUNT(*) FROM wall_cards WHERE session_id = ? AND session_block_id = ? AND created_by = ?');
            $c->execute([$p->sessionId, $blockId, $p->participantId]);
            if ((int) $c->fetchColumn() >= self::MAX_CARDS_PER_PARTICIPANT) {
                throw new ValidationException([sprintf('You can add at most %d cards to this wall.', self::MAX_CARDS_PER_PARTICIPANT)]);
            }
            $id = $this->insert($pdo, $p->sessionId, $blockId, $p->participantId, $p->moderationActorIdBinary(), $text, $columnId);
            $this->participants->recordActivity($p);
            return $id;
        });
        $this->notify($p->sessionId, $blockId);
        return $id;
    }

    /** Tutor-created card (e.g. seeding a retro). */
    public function tutorAddCard(TenantDb $tenant, int $sessionId, int $blockId, mixed $text, mixed $columnId): ?int
    {
        if (!$this->ownsSession($tenant, $sessionId)) {
            return null;
        }
        $id = Transaction::run($this->pdo, function (PDO $pdo) use ($sessionId, $blockId, $text, $columnId): int {
            $config = $this->lockOpenWall($pdo, $sessionId, $blockId, false);
            [$text, $columnId] = self::validateCard($config, $text, $columnId);
            return $this->insert($pdo, $sessionId, $blockId, null, self::TUTOR_ACTOR, $text, $columnId);
        });
        $this->notify($sessionId, $blockId);
        return $id;
    }

    /** Participant moves one of their own cards (LWW on column/position). */
    public function moveCard(ParticipantContext $p, int $cardId, mixed $columnId, mixed $position): bool
    {
        return $this->mutate($p->sessionId, $cardId, $p->participantId, true, function (PDO $pdo, array $card, array $config) use ($columnId, $position): void {
            $this->applyMove($pdo, $card, $config, $columnId, $position);
        });
    }

    public function tutorMoveCard(TenantDb $tenant, int $sessionId, int $cardId, mixed $columnId, mixed $position): bool
    {
        return $this->ownsSession($tenant, $sessionId) && $this->mutate($sessionId, $cardId, null, false, function (PDO $pdo, array $card, array $config) use ($columnId, $position): void {
            $this->applyMove($pdo, $card, $config, $columnId, $position);
        });
    }

    public function editCard(ParticipantContext $p, int $cardId, mixed $text): bool
    {
        return $this->mutate($p->sessionId, $cardId, $p->participantId, true, function (PDO $pdo, array $card) use ($text): void {
            $clean = Text::clean($text, Limits::WALL_CARD, true) ?? throw new ValidationException([sprintf('Cards must be 1–%d characters.', Limits::WALL_CARD)]);
            $pdo->prepare('UPDATE wall_cards SET text = ?, updated_at = ? WHERE id = ?')->execute([$clean, Time::toDb($this->clock->now()), $card['id']]);
        });
    }

    public function deleteCard(ParticipantContext $p, int $cardId): bool
    {
        return $this->mutate($p->sessionId, $cardId, $p->participantId, true, function (PDO $pdo, array $card): void {
            $pdo->prepare('DELETE FROM wall_cards WHERE id = ?')->execute([$card['id']]);
        });
    }

    public function tutorDeleteCard(TenantDb $tenant, int $sessionId, int $cardId): bool
    {
        return $this->ownsSession($tenant, $sessionId) && $this->mutate($sessionId, $cardId, null, false, function (PDO $pdo, array $card): void {
            $pdo->prepare('DELETE FROM wall_cards WHERE id = ?')->execute([$card['id']]);
        });
    }

    /**
     * Cards of a wall block. Participants see text, column, position and whether a card is
     * their own; the tutor view additionally carries the opaque actor id for moderation.
     *
     * @return list<array<string,mixed>>
     */
    public function cards(int $sessionId, int $blockId, ?ParticipantContext $viewer, bool $tutorView = false): array
    {
        $s = $this->pdo->prepare(
            'SELECT id, created_by, HEX(moderation_actor_id) AS actor, text, column_id, position
             FROM wall_cards WHERE session_id = ? AND session_block_id = ? ORDER BY column_id, position, id'
        );
        $s->execute([$sessionId, $blockId]);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $card = ['id' => (int) $r['id'], 'column_id' => $r['column_id'], 'position' => (int) $r['position'], 'text' => $r['text']];
            if ($viewer !== null) {
                $card['mine'] = $r['created_by'] !== null && (int) $r['created_by'] === $viewer->participantId;
            }
            if ($tutorView) {
                $card['actor'] = $r['created_by'] === null ? 'tutor' : strtolower($r['actor']);
            }
            $out[] = $card;
        }
        return $out;
    }

    /**
     * Moderation: remove all cards of one participant in a session (by opaque actor id).
     *
     * @return list<int> affected block ids
     */
    public function removeActor(TenantDb $tenant, int $sessionId, string $actorHex): array
    {
        $bin = SubmissionService::actorBin($actorHex);
        $blocks = array_map(static fn ($r) => (int) $r['session_block_id'], $tenant->all(
            'SELECT DISTINCT w.session_block_id FROM wall_cards w JOIN sessions s ON s.id = w.session_id
             WHERE s.id = :sid AND s.tenant_id = :tenant_id AND w.moderation_actor_id = :actor',
            ['sid' => $sessionId, 'actor' => $bin],
        ));
        $tenant->run(
            'DELETE w FROM wall_cards w JOIN sessions s ON s.id = w.session_id
             WHERE s.id = :sid AND s.tenant_id = :tenant_id AND w.moderation_actor_id = :actor',
            ['sid' => $sessionId, 'actor' => $bin],
        );
        foreach ($blocks as $b) {
            $this->notify($sessionId, $b);
        }
        return $blocks;
    }

    /**
     * @param callable(PDO, array<string,mixed>, array<string,mixed>):void $fn
     */
    private function mutate(int $sessionId, int $cardId, ?int $ownerParticipantId, bool $requireCurrent, callable $fn): bool
    {
        $blockId = Transaction::run($this->pdo, function (PDO $pdo) use ($sessionId, $cardId, $ownerParticipantId, $requireCurrent, $fn): ?int {
            $s = $pdo->prepare('SELECT * FROM wall_cards WHERE id = ? AND session_id = ? FOR UPDATE');
            $s->execute([$cardId, $sessionId]);
            $card = $s->fetch();
            if ($card === false || ($ownerParticipantId !== null && (int) $card['created_by'] !== $ownerParticipantId)) {
                return null;
            }
            $config = $this->lockOpenWall($pdo, $sessionId, (int) $card['session_block_id'], $requireCurrent);
            $fn($pdo, $card, $config);
            return (int) $card['session_block_id'];
        });
        if ($blockId === null) {
            return false;
        }
        $this->notify($sessionId, $blockId);
        return true;
    }

    private function applyMove(PDO $pdo, array $card, array $config, mixed $columnId, mixed $position): void
    {
        if (!is_string($columnId) || !in_array($columnId, array_column($config['columns'], 'id'), true)) {
            throw new ValidationException(['Unknown column.']);
        }
        if (!is_int($position) || $position < 0 || $position > 10000) {
            throw new ValidationException(['Invalid position.']);
        }
        // make room at the target position, then place the card there (last write wins)
        $pdo->prepare('UPDATE wall_cards SET position = position + 1 WHERE session_id = ? AND session_block_id = ? AND column_id = ? AND position >= ? AND id <> ?')
            ->execute([$card['session_id'], $card['session_block_id'], $columnId, $position, $card['id']]);
        $pdo->prepare('UPDATE wall_cards SET column_id = ?, position = ?, updated_at = ? WHERE id = ?')
            ->execute([$columnId, $position, Time::toDb($this->clock->now()), $card['id']]);
    }

    /**
     * @return array<string,mixed> wall config
     * @throws BlockNotOpen
     */
    private function lockOpenWall(PDO $pdo, int $sessionId, int $blockId, bool $requireCurrent): array
    {
        $s = $pdo->prepare(
            "SELECT s.status, s.current_session_block_id, b.block_type, b.config_snapshot
             FROM sessions s JOIN session_blocks b ON b.session_id = s.id
             WHERE s.id = ? AND b.id = ? LOCK IN SHARE MODE"
        );
        $s->execute([$sessionId, $blockId]);
        $row = $s->fetch();
        if ($row === false || $row['status'] !== 'live' || $row['block_type'] !== 'wall'
            || ($requireCurrent && (int) $row['current_session_block_id'] !== $blockId)) {
            throw new BlockNotOpen();
        }
        return json_decode((string) $row['config_snapshot'], true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array{string,string} */
    private static function validateCard(array $config, mixed $text, mixed $columnId): array
    {
        $clean = Text::clean($text, Limits::WALL_CARD, true);
        if ($clean === null) {
            throw new ValidationException([sprintf('Cards must be 1–%d characters.', Limits::WALL_CARD)]);
        }
        if (!is_string($columnId) || !in_array($columnId, array_column($config['columns'], 'id'), true)) {
            throw new ValidationException(['Unknown column.']);
        }
        return [$clean, $columnId];
    }

    private function insert(PDO $pdo, int $sessionId, int $blockId, ?int $participantId, string $actor, string $text, string $columnId): int
    {
        $pos = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM wall_cards WHERE session_id = ? AND session_block_id = ? AND column_id = ?');
        $pos->execute([$sessionId, $blockId, $columnId]);
        $now = Time::toDb($this->clock->now());
        $pdo->prepare(
            'INSERT INTO wall_cards (session_id, session_block_id, created_by, moderation_actor_id, text, column_id, position, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$sessionId, $blockId, $participantId, $actor, $text, $columnId, (int) $pos->fetchColumn(), $now, $now]);
        return (int) $pdo->lastInsertId();
    }

    private function ownsSession(TenantDb $tenant, int $sessionId): bool
    {
        return $tenant->one('SELECT id FROM sessions WHERE id = :id AND tenant_id = :tenant_id', ['id' => $sessionId]) !== null;
    }

    private function notify(int $sessionId, int $blockId): void
    {
        $this->broadcaster->broadcast($sessionId, ['type' => 'wall_update', 'session_block_id' => $blockId]);
    }
}
