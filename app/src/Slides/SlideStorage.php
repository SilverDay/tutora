<?php

declare(strict_types=1);

namespace Tutora\Slides;

/**
 * File layout under STORAGE_PATH (must be outside the web root):
 *   staging/<random>.<ext>          uploaded originals awaiting conversion
 *   jobs/<job>-<random>/{in,out}    per-job working dirs (deleted after each job)
 *   slides/<tenant>/<import>/<n>.png converted, validated page images
 */
final class SlideStorage
{
    public function __construct(private readonly string $root)
    {
        if ($root === '' || !str_starts_with($root, '/')) {
            throw new \InvalidArgumentException('STORAGE_PATH must be an absolute path');
        }
    }

    public function stagingDir(): string
    {
        return $this->ensure($this->root . '/staging');
    }

    public function jobsDir(): string
    {
        return $this->ensure($this->root . '/jobs');
    }

    public function importDir(int $tenantId, int $importId): string
    {
        return $this->ensure($this->root . "/slides/{$tenantId}/{$importId}");
    }

    public function slidesRoot(): string
    {
        return $this->ensure($this->root . '/slides');
    }

    /** Resolves a stored path and guarantees it lies inside the slides directory. */
    public function resolveAsset(string $path): ?string
    {
        $real = realpath($path);
        $root = realpath($this->slidesRoot());
        if ($real === false || $root === false || !str_starts_with($real, $root . '/') || !is_file($real)) {
            return null;
        }
        return $real;
    }

    public function deleteImport(int $tenantId, int $importId): void
    {
        self::removeTree($this->root . "/slides/{$tenantId}/{$importId}");
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            if (is_file($dir) || is_link($dir)) {
                @unlink($dir);
            }
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }

    private function ensure(string $dir): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create storage directory');
        }
        return $dir;
    }
}
