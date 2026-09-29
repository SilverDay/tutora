<?php

declare(strict_types=1);

namespace Tutora\Slides;

use Tutora\Security\RateLimiter;
use Tutora\Support\Clock;
use Tutora\Support\Time;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantDb;

/**
 * Tutor-side slide imports (tenant-scoped): upload -> staging + conversion job; listing of
 * imports with their assets.
 */
final class SlideImportService
{
    public const UPLOADS_PER_HOUR = 20;

    public function __construct(
        private readonly TenantDb $db,
        private readonly SlideStorage $storage,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @param string $tmpPath already verified as an uploaded file by the caller
     * @return int|null slide_import id, or null if the workshop is not the tenant's
     * @throws ValidationException
     */
    public function upload(int $workshopId, string $tmpPath, string $originalName): ?int
    {
        if ($this->db->one('SELECT id FROM workshops WHERE id = :id AND tenant_id = :tenant_id', ['id' => $workshopId]) === null) {
            return null;
        }
        $type = UploadValidator::detect($tmpPath, $originalName, $this->maxBytes);
        if (!$this->limiter->consume('slide-upload:' . $this->db->tenantId(), self::UPLOADS_PER_HOUR, 3600)) {
            throw new ValidationException(['Too many uploads. Please try again later.']);
        }
        $staged = $this->storage->stagingDir() . '/' . bin2hex(random_bytes(16)) . '.' . $type;
        if (!@rename($tmpPath, $staged) && !(@copy($tmpPath, $staged) && @unlink($tmpPath))) {
            throw new \RuntimeException('Could not stage upload');
        }
        chmod($staged, 0640);
        try {
            return $this->db->transaction(function (TenantDb $db) use ($workshopId, $originalName, $staged): int {
                $now = Time::toDb($this->clock->now());
                // ownership of $workshopId was checked above; the row is written for this tenant only
                $db->run(
                    'INSERT INTO slide_imports (tenant_id, workshop_id, original_filename, page_count, created_at)
                     VALUES (:tenant_id, :wid, :name, NULL, :now)',
                    ['name' => UploadValidator::displayName($originalName), 'now' => $now, 'wid' => $workshopId],
                );
                $importId = $db->lastInsertId();
                $db->run(
                    "INSERT INTO conversion_jobs (tenant_id, workshop_id, slide_import_id, source_path, status, created_at, updated_at)
                     VALUES (:tenant_id, :wid, :iid, :path, 'pending', :now, :now2)",
                    ['wid' => $workshopId, 'iid' => $importId, 'path' => $staged, 'now' => $now, 'now2' => $now],
                );
                return $importId;
            });
        } catch (\Throwable $e) {
            @unlink($staged);
            throw $e;
        }
    }

    /**
     * Imports of a workshop with job status and assets.
     *
     * @return list<array<string,mixed>>
     */
    public function imports(int $workshopId): array
    {
        $imports = $this->db->all(
            'SELECT i.id, i.original_filename, i.page_count, i.created_at, j.status, j.error_message
             FROM slide_imports i LEFT JOIN conversion_jobs j ON j.slide_import_id = i.id
             WHERE i.workshop_id = :wid AND i.tenant_id = :tenant_id ORDER BY i.id DESC',
            ['wid' => $workshopId],
        );
        foreach ($imports as &$imp) {
            $imp['assets'] = $this->db->all(
                'SELECT a.id, a.page_number, a.width, a.height FROM slide_assets a JOIN slide_imports i ON i.id = a.slide_import_id
                 WHERE a.slide_import_id = :iid AND i.tenant_id = :tenant_id ORDER BY a.page_number',
                ['iid' => (int) $imp['id']],
            );
        }
        return $imports;
    }

    /** @return list<int> asset ids of an import (tenant-scoped), in page order */
    public function assetIds(int $importId): array
    {
        return array_map(static fn ($r) => (int) $r['id'], $this->db->all(
            'SELECT a.id FROM slide_assets a JOIN slide_imports i ON i.id = a.slide_import_id
             WHERE i.id = :iid AND i.tenant_id = :tenant_id ORDER BY a.page_number',
            ['iid' => $importId],
        ));
    }

    /**
     * Removes still-staged uploads of a workshop (called before the workshop is deleted;
     * its conversion jobs cascade). Converted images are not touched here: the imports are
     * detached (workshop_id = NULL) and collectOrphans() decides (owner decision 8).
     */
    public function deleteStagedSources(int $workshopId): void
    {
        $staging = $this->storage->stagingDir() . '/';
        foreach ($this->db->all('SELECT source_path FROM conversion_jobs WHERE workshop_id = :wid AND tenant_id = :tenant_id', ['wid' => $workshopId]) as $r) {
            if (str_starts_with((string) $r['source_path'], $staging)) {
                @unlink((string) $r['source_path']);
            }
        }
    }

    /**
     * Deletes this tenant's detached imports (workshop deleted) whose assets no session
     * block references any more: rows first (in a transaction, locked), then the files.
     * Called after a workshop or a session was deleted. Race-free: a detached import has
     * no workshop blocks, so no new session can start referencing it.
     *
     * @return int number of imports removed
     */
    public function collectOrphans(): int
    {
        $ids = $this->db->transaction(function (TenantDb $db): array {
            $rows = $db->all(
                'SELECT i.id FROM slide_imports i
                 WHERE i.tenant_id = :tenant_id AND i.workshop_id IS NULL
                   AND NOT EXISTS (SELECT 1 FROM slide_assets a JOIN session_blocks b ON b.slide_asset_id = a.id
                                   WHERE a.slide_import_id = i.id)
                 FOR UPDATE',
            );
            $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
            foreach ($ids as $id) {
                $db->run('DELETE FROM slide_imports WHERE id = :id AND tenant_id = :tenant_id AND workshop_id IS NULL', ['id' => $id]);
            }
            return $ids;
        });
        foreach ($ids as $id) {
            $this->storage->deleteImport($this->db->tenantId(), $id);
        }
        return count($ids);
    }
}
