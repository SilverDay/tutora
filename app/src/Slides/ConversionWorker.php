<?php

declare(strict_types=1);

namespace Tutora\Slides;

use PDO;
use Tutora\Database\Transaction;
use Tutora\Security\Logger;
use Tutora\Support\Clock;
use Tutora\Support\Time;

/**
 * Processes conversion_jobs (spec: Slide Conversion Pipeline steps 2-6).
 *
 * The converter's output is untrusted: only regular files named page-N.png with contiguous
 * N, a PNG signature, bounded size and dimensions are accepted; symlinks are rejected so a
 * compromised converter cannot make this process read host files.
 */
final class ConversionWorker
{
    public const MAX_ATTEMPTS = 3;
    public const FAILED_SOURCE_RETENTION = 86400;
    public const MAX_IMAGE_BYTES = 20 * 1048576;
    public const MAX_IMAGE_SIDE = 4096;

    private const MESSAGES = [
        2 => 'The file could not be read as a presentation.',
        3 => 'The presentation has too many pages.',
        4 => 'The presentation could not be converted.',
        124 => 'Conversion took too long.',
        'output' => 'The converted slides failed validation.',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SlideStorage $storage,
        private readonly ConverterRunner $runner,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly int $timeoutSeconds = 120,
        private readonly int $maxPages = 300,
    ) {
    }

    /** Processes the oldest pending job. Returns its id, or null if there was none. */
    public function processNext(): ?int
    {
        $job = $this->claim();
        if ($job === null) {
            return null;
        }
        $id = (int) $job['id'];
        $jobDir = $this->storage->jobsDir() . '/' . $id . '-' . bin2hex(random_bytes(6));
        try {
            $this->prepare($job, $jobDir);
            $result = $this->runner->run($jobDir . '/in', $jobDir . '/out', $this->timeoutSeconds, $this->maxPages);
            if ($result->timedOut || $result->exitCode !== 0) {
                $this->fail($job, $result->timedOut ? 124 : $result->exitCode);
                return $id;
            }
            $pages = $this->validateOutput($jobDir . '/out');
            if ($pages === null) {
                $this->fail($job, 'output');
                return $id;
            }
            $this->persist($job, $pages);
            @unlink((string) $job['source_path']);
        } catch (\Throwable $e) {
            $this->logger->error('Conversion job error', ['job_id' => $id, 'class' => $e::class]);
            $this->fail($job, 'internal');
        } finally {
            // intermediate files are removed regardless of outcome, after the DB write
            SlideStorage::removeTree($jobDir);
        }
        return $id;
    }

    /** Jobs stuck in "running" (crashed daemon) go back to pending, or fail after MAX_ATTEMPTS. */
    public function recoverStale(): int
    {
        $cutoff = Time::toDb($this->clock->now()->modify('-' . ($this->timeoutSeconds + 60) . ' seconds'));
        $s = $this->pdo->prepare("SELECT * FROM conversion_jobs WHERE status = 'running' AND locked_at < ?");
        $s->execute([$cutoff]);
        $n = 0;
        foreach ($s->fetchAll() as $job) {
            if ((int) $job['attempts'] < self::MAX_ATTEMPTS) {
                $this->pdo->prepare("UPDATE conversion_jobs SET status = 'pending', locked_at = NULL, updated_at = ? WHERE id = ? AND status = 'running'")
                    ->execute([Time::toDb($this->clock->now()), $job['id']]);
            } else {
                $this->fail($job, 'internal');
            }
            $n++;
        }
        return $n;
    }

    /** Deletes original uploads of failed jobs after at most 24 h (spec: bounded retention). */
    public function purgeFailedSources(): int
    {
        $s = $this->pdo->prepare("SELECT id, source_path FROM conversion_jobs WHERE status = 'failed' AND source_purge_after IS NOT NULL AND source_purge_after <= ?");
        $s->execute([Time::toDb($this->clock->now())]);
        $n = 0;
        foreach ($s->fetchAll() as $row) {
            $path = (string) $row['source_path'];
            if ($path !== '' && str_starts_with($path, $this->storage->stagingDir() . '/')) {
                @unlink($path);
            }
            $this->pdo->prepare('UPDATE conversion_jobs SET source_purge_after = NULL, updated_at = ? WHERE id = ?')
                ->execute([Time::toDb($this->clock->now()), $row['id']]);
            $n++;
        }
        return $n;
    }

    /** @return array<string,mixed>|null */
    private function claim(): ?array
    {
        return Transaction::run($this->pdo, function (PDO $pdo): ?array {
            $stmt = $pdo->query("SELECT * FROM conversion_jobs WHERE status = 'pending' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED");
            $job = $stmt === false ? false : $stmt->fetch();
            if ($job === false) {
                return null;
            }
            $now = Time::toDb($this->clock->now());
            $pdo->prepare("UPDATE conversion_jobs SET status = 'running', attempts = attempts + 1, locked_at = ?, updated_at = ? WHERE id = ?")
                ->execute([$now, $now, $job['id']]);
            return $job;
        });
    }

    /**
     * @param array<string,mixed> $job
     */
    private function prepare(array $job, string $jobDir): void
    {
        $source = (string) $job['source_path'];
        $ext = pathinfo($source, PATHINFO_EXTENSION);
        if (!in_array($ext, [UploadValidator::PDF, UploadValidator::PPTX], true) || !is_file($source)) {
            throw new \RuntimeException('Source missing');
        }
        mkdir($jobDir . '/in', 0755, true);
        mkdir($jobDir . '/out', 0700, true);
        copy($source, $jobDir . "/in/source.{$ext}");
        chmod($jobDir . "/in/source.{$ext}", 0644);
        // the output dir must be writable by the converter's uid (never root)
        [$uid, $gid] = array_map('intval', explode(':', $this->runner->containerUser()));
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            chown($jobDir . '/out', $uid);
            chgrp($jobDir . '/out', $gid);
        }
    }

    /** @return list<array{path:string,page:int,width:int,height:int}>|null */
    private function validateOutput(string $outDir): ?array
    {
        $pages = [];
        foreach (scandir($outDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $outDir . '/' . $entry;
            if (preg_match('/^page-(\d{1,4})\.png$/D', $entry, $m) !== 1 || is_link($path) || !is_file($path)) {
                return null;
            }
            $size = filesize($path);
            if ($size === false || $size < 8 || $size > self::MAX_IMAGE_BYTES) {
                return null;
            }
            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                return null;
            }
            $sig = fread($fh, 8);
            fclose($fh);
            $info = @getimagesize($path);
            if ($sig !== "\x89PNG\r\n\x1a\n" || $info === false || $info[2] !== IMAGETYPE_PNG
                || $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_IMAGE_SIDE || $info[1] > self::MAX_IMAGE_SIDE) {
                return null;
            }
            $pages[(int) $m[1]] = ['path' => $path, 'page' => (int) $m[1], 'width' => $info[0], 'height' => $info[1]];
        }
        ksort($pages);
        $n = count($pages);
        if ($n === 0 || $n > $this->maxPages || array_keys($pages) !== range(1, $n)) {
            return null;
        }
        return array_values($pages);
    }

    /**
     * @param list<array{path:string,page:int,width:int,height:int}> $pages
     *
     * @param array<string,mixed> $job
     */
    private function persist(array $job, array $pages): void
    {
        $dir = $this->storage->importDir((int) $job['tenant_id'], (int) $job['slide_import_id']);
        $stored = [];
        foreach ($pages as $p) {
            $dest = $dir . '/' . $p['page'] . '.png';
            if (!copy($p['path'], $dest)) {
                throw new \RuntimeException('Could not store page');
            }
            chmod($dest, 0640);
            $stored[] = $p + ['dest' => $dest];
        }
        try {
            Transaction::run($this->pdo, function (PDO $pdo) use ($job, $stored): void {
                $ins = $pdo->prepare('INSERT INTO slide_assets (slide_import_id, page_number, image_path, width, height) VALUES (?, ?, ?, ?, ?)');
                foreach ($stored as $p) {
                    $ins->execute([$job['slide_import_id'], $p['page'], $p['dest'], $p['width'], $p['height']]);
                }
                $pdo->prepare('UPDATE slide_imports SET page_count = ? WHERE id = ?')->execute([count($stored), $job['slide_import_id']]);
                $pdo->prepare("UPDATE conversion_jobs SET status = 'done', error_message = NULL, locked_at = NULL, source_purge_after = NULL, updated_at = ? WHERE id = ?")
                    ->execute([Time::toDb($this->clock->now()), $job['id']]);
            });
        } catch (\Throwable $e) {
            $this->storage->deleteImport((int) $job['tenant_id'], (int) $job['slide_import_id']);
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $job
     */
    private function fail(array $job, int|string $reason): void
    {
        // an unavailable runtime (125 etc.) is retried; deterministic converter failures are not
        $retryable = is_int($reason) && !isset(self::MESSAGES[$reason]) || $reason === 'internal';
        $now = $this->clock->now();
        if ($retryable && (int) $job['attempts'] + ($job['status'] === 'running' ? 0 : 1) < self::MAX_ATTEMPTS) {
            $this->pdo->prepare("UPDATE conversion_jobs SET status = 'pending', locked_at = NULL, updated_at = ? WHERE id = ?")
                ->execute([Time::toDb($now), $job['id']]);
            return;
        }
        $message = self::MESSAGES[$reason] ?? 'The presentation could not be converted.';
        $this->pdo->prepare(
            "UPDATE conversion_jobs SET status = 'failed', error_message = ?, locked_at = NULL, source_purge_after = ?, updated_at = ? WHERE id = ?"
        )->execute([$message, Time::toDb($now->modify('+' . self::FAILED_SOURCE_RETENTION . ' seconds')), Time::toDb($now), $job['id']]);
        $this->logger->warning('Conversion job failed', ['job_id' => (int) $job['id'], 'reason' => (string) $reason]);
    }
}
