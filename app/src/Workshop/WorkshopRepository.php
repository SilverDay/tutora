<?php

declare(strict_types=1);

namespace Tutora\Workshop;

use Tutora\Block\BlockConfig;
use Tutora\Block\BlockType;
use Tutora\Support\Clock;
use Tutora\Support\Limits;
use Tutora\Support\Time;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantDb;

/**
 * Workshop templates and their ordered blocks. All access is tenant-scoped: child
 * tables are always reached through a join on workshops.tenant_id = :tenant_id.
 */
final class WorkshopRepository
{
    public function __construct(private readonly TenantDb $db, private readonly Clock $clock)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return $this->db->all(
            'SELECT w.id, w.title, w.description, w.updated_at,
                    (SELECT COUNT(*) FROM workshop_blocks b WHERE b.workshop_id = w.id) AS block_count
             FROM workshops w WHERE w.tenant_id = :tenant_id ORDER BY w.updated_at DESC, w.id DESC'
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM workshops WHERE id = :id AND tenant_id = :tenant_id', ['id' => $id]);
    }

    public function create(string $title, ?string $description): int
    {
        [$title, $description] = self::validateMeta($title, $description);
        $now = Time::toDb($this->clock->now());
        $this->db->run(
            'INSERT INTO workshops (tenant_id, title, description, created_at, updated_at) VALUES (:tenant_id, :t, :d, :now, :now2)',
            ['t' => $title, 'd' => $description, 'now' => $now, 'now2' => $now],
        );
        return $this->db->lastInsertId();
    }

    public function update(int $id, string $title, ?string $description): bool
    {
        [$title, $description] = self::validateMeta($title, $description);
        return $this->db->run(
            'UPDATE workshops SET title = :t, description = :d, updated_at = :now WHERE id = :id AND tenant_id = :tenant_id',
            ['t' => $title, 'd' => $description, 'now' => Time::toDb($this->clock->now()), 'id' => $id],
        )->rowCount() > 0 || $this->find($id) !== null;
    }

    /**
     * Deletes a workshop template. Sessions that ran from it keep their snapshot
     * (sessions.workshop_id is SET NULL). Blocks are removed first so that the
     * RESTRICT from workshop_blocks to slide_assets never depends on cascade order.
     */
    public function delete(int $id): bool
    {
        return $this->db->transaction(function (TenantDb $db) use ($id): bool {
            if ($db->one('SELECT id FROM workshops WHERE id = :id AND tenant_id = :tenant_id FOR UPDATE', ['id' => $id]) === null) {
                return false;
            }
            $db->run(
                'DELETE b FROM workshop_blocks b JOIN workshops w ON w.id = b.workshop_id WHERE w.id = :id AND w.tenant_id = :tenant_id',
                ['id' => $id],
            );
            return $db->run('DELETE FROM workshops WHERE id = :id AND tenant_id = :tenant_id', ['id' => $id])->rowCount() === 1;
        });
    }

    /** @return list<array<string,mixed>> blocks with decoded config */
    public function blocks(int $workshopId): array
    {
        $rows = $this->db->all(
            'SELECT b.* FROM workshop_blocks b JOIN workshops w ON w.id = b.workshop_id
             WHERE b.workshop_id = :wid AND w.tenant_id = :tenant_id ORDER BY b.position, b.id',
            ['wid' => $workshopId],
        );
        return array_map(self::decodeBlock(...), $rows);
    }

    /** @return array<string,mixed>|null */
    public function findBlock(int $blockId): ?array
    {
        $row = $this->db->one(
            'SELECT b.* FROM workshop_blocks b JOIN workshops w ON w.id = b.workshop_id
             WHERE b.id = :id AND w.tenant_id = :tenant_id',
            ['id' => $blockId],
        );
        return $row === null ? null : self::decodeBlock($row);
    }

    /**
     * @param array<string,mixed> $config
     * @throws ValidationException
     */
    public function addBlock(int $workshopId, BlockType $type, array $config, ?int $slideAssetId = null): ?int
    {
        $normalized = BlockConfig::validate($type, $config);
        return $this->db->transaction(function (TenantDb $db) use ($workshopId, $type, $normalized, $slideAssetId): ?int {
            if ($db->one('SELECT id FROM workshops WHERE id = :id AND tenant_id = :tenant_id FOR UPDATE', ['id' => $workshopId]) === null) {
                return null;
            }
            $this->assertAsset($db, $workshopId, $type, $slideAssetId);
            $count = (int) ($db->one(
                'SELECT COUNT(*) AS n FROM workshop_blocks b JOIN workshops w ON w.id = b.workshop_id WHERE w.id = :id AND w.tenant_id = :tenant_id',
                ['id' => $workshopId],
            )['n'] ?? 0);
            if ($count >= Limits::MAX_BLOCKS_PER_WORKSHOP) {
                throw new ValidationException(['This workshop already has the maximum number of blocks.']);
            }
            $now = Time::toDb($this->clock->now());
            $db->run(
                'INSERT INTO workshop_blocks (workshop_id, position, block_type, config, config_version, slide_asset_id, created_at, updated_at)
                 SELECT w.id, :pos, :type, :config, :ver, :asset, :now, :now2 FROM workshops w WHERE w.id = :wid AND w.tenant_id = :tenant_id',
                ['pos' => $count, 'type' => $type->value, 'config' => json_encode($normalized, JSON_THROW_ON_ERROR),
                 'ver' => BlockConfig::CURRENT_VERSION, 'asset' => $slideAssetId, 'now' => $now, 'now2' => $now, 'wid' => $workshopId],
            );
            $id = $db->lastInsertId();
            $this->touch($db, $workshopId);
            return $id;
        });
    }

    /**
     * @param array<string,mixed> $config
     * @throws ValidationException
     */
    public function updateBlock(int $blockId, array $config, ?int $slideAssetId = null): bool
    {
        $block = $this->findBlock($blockId);
        if ($block === null) {
            return false;
        }
        $type = BlockType::from($block['block_type']);
        $normalized = BlockConfig::validate($type, $config);
        return $this->db->transaction(function (TenantDb $db) use ($block, $blockId, $type, $normalized, $slideAssetId): bool {
            $this->assertAsset($db, (int) $block['workshop_id'], $type, $slideAssetId);
            $db->run(
                'UPDATE workshop_blocks b JOIN workshops w ON w.id = b.workshop_id
                 SET b.config = :config, b.config_version = :ver, b.slide_asset_id = :asset, b.updated_at = :now
                 WHERE b.id = :id AND w.tenant_id = :tenant_id',
                ['config' => json_encode($normalized, JSON_THROW_ON_ERROR), 'ver' => BlockConfig::CURRENT_VERSION,
                 'asset' => $slideAssetId, 'now' => Time::toDb($this->clock->now()), 'id' => $blockId],
            );
            $this->touch($db, (int) $block['workshop_id']);
            return true;
        });
    }

    public function deleteBlock(int $blockId): bool
    {
        $block = $this->findBlock($blockId);
        if ($block === null) {
            return false;
        }
        return $this->db->transaction(function (TenantDb $db) use ($block, $blockId): bool {
            $db->run(
                'DELETE b FROM workshop_blocks b JOIN workshops w ON w.id = b.workshop_id WHERE b.id = :id AND w.tenant_id = :tenant_id',
                ['id' => $blockId],
            );
            $this->renumber($db, (int) $block['workshop_id'], null, 0);
            $this->touch($db, (int) $block['workshop_id']);
            return true;
        });
    }

    /** Moves a block to $newPosition (0-based), shifting the others. */
    public function moveBlock(int $blockId, int $newPosition): bool
    {
        $block = $this->findBlock($blockId);
        if ($block === null) {
            return false;
        }
        return $this->db->transaction(function (TenantDb $db) use ($block, $blockId, $newPosition): bool {
            $this->renumber($db, (int) $block['workshop_id'], $blockId, $newPosition);
            $this->touch($db, (int) $block['workshop_id']);
            return true;
        });
    }

    /** Rewrites positions as 0..n-1, optionally placing $movedId at $target. */
    private function renumber(TenantDb $db, int $workshopId, ?int $movedId, int $target): void
    {
        $ids = array_map(static fn ($r) => (int) $r['id'], $db->all(
            'SELECT b.id FROM workshop_blocks b JOIN workshops w ON w.id = b.workshop_id
             WHERE w.id = :wid AND w.tenant_id = :tenant_id ORDER BY b.position, b.id FOR UPDATE',
            ['wid' => $workshopId],
        ));
        if ($movedId !== null) {
            $ids = array_values(array_filter($ids, static fn ($id) => $id !== $movedId));
            array_splice($ids, max(0, min($target, count($ids))), 0, [$movedId]);
        }
        foreach ($ids as $pos => $id) {
            $db->run(
                'UPDATE workshop_blocks b JOIN workshops w ON w.id = b.workshop_id SET b.position = :pos
                 WHERE b.id = :id AND w.tenant_id = :tenant_id',
                ['pos' => $pos, 'id' => $id],
            );
        }
    }

    private function assertAsset(TenantDb $db, int $workshopId, BlockType $type, ?int $slideAssetId): void
    {
        if ($type === BlockType::Slide && $slideAssetId === null) {
            throw new ValidationException(['A slide block needs a slide image.']);
        }
        if ($slideAssetId === null) {
            return;
        }
        if (!in_array($type, [BlockType::Slide, BlockType::Whiteboard, BlockType::Annotate], true)) {
            throw new ValidationException(['This block type cannot have a slide image.']);
        }
        $ok = $db->one(
            'SELECT a.id FROM slide_assets a JOIN slide_imports i ON i.id = a.slide_import_id
             WHERE a.id = :aid AND i.workshop_id = :wid AND i.tenant_id = :tenant_id',
            ['aid' => $slideAssetId, 'wid' => $workshopId],
        );
        if ($ok === null) {
            throw new ValidationException(['The selected slide image does not belong to this workshop.']);
        }
    }

    private function touch(TenantDb $db, int $workshopId): void
    {
        $db->run('UPDATE workshops SET updated_at = :now WHERE id = :id AND tenant_id = :tenant_id', ['now' => Time::toDb($this->clock->now()), 'id' => $workshopId]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function decodeBlock(array $row): array
    {
        $row['config'] = json_decode((string) $row['config'], true, 64, JSON_THROW_ON_ERROR);
        return $row;
    }

    /** @return array{string,?string} */
    private static function validateMeta(string $title, ?string $description): array
    {
        $title = trim($title);
        $description = $description === null ? null : trim($description);
        $errors = [];
        if ($title === '' || mb_strlen($title, 'UTF-8') > Limits::WORKSHOP_TITLE || !mb_check_encoding($title, 'UTF-8')) {
            $errors[] = sprintf('Title must be 1–%d characters.', Limits::WORKSHOP_TITLE);
        }
        if ($description !== null && (mb_strlen($description, 'UTF-8') > Limits::WORKSHOP_DESCRIPTION || !mb_check_encoding($description, 'UTF-8'))) {
            $errors[] = sprintf('Description must be at most %d characters.', Limits::WORKSHOP_DESCRIPTION);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [$title, $description === '' ? null : $description];
    }
}
