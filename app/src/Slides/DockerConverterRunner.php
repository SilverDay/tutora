<?php

declare(strict_types=1);

namespace Tutora\Slides;

/**
 * Runs one conversion job in a fresh container with the sandbox profile required by the
 * spec (Deployment & Infrastructure). "Runs in Docker" is not itself the boundary; the
 * boundary is this explicit profile:
 *   network none · non-root user · cap_drop ALL · no-new-privileges · read-only rootfs ·
 *   job-specific bind mounts only (input read-only) · noexec tmpfs · pids/memory/CPU limits ·
 *   default (or configured) seccomp profile · optional AppArmor profile ·
 *   wall-clock timeout enforced out here, not inside the container.
 *
 * The container is created first ("create", waited for) and only then started ("start -a")
 * under the job timeout, so a timeout can never race container creation: the container
 * always exists when it is removed by name, on every exit path.
 *
 * The command is an argv array (no shell). Container output is discarded so document
 * content never reaches logs.
 */
final class DockerConverterRunner implements ConverterRunner
{
    /** Upper bound for "create" alone (image is local; this is only a hang guard). */
    private const CREATE_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly string $image,
        private readonly string $runtime = 'docker',
        private readonly string $user = '65532:65532',
        private readonly string $memory = '1g',
        private readonly string $cpus = '1',
        private readonly int $pidsLimit = 256,
        private readonly ?string $seccompProfile = null,
        private readonly ?string $apparmorProfile = null,
    ) {
        if (preg_match('/^\d+:\d+$/', $user) !== 1 || str_starts_with($user, '0:')) {
            throw new \InvalidArgumentException('Converter must run as a numeric non-root uid:gid');
        }
    }

    public function containerUser(): string
    {
        return $this->user;
    }

    /** @return list<string> the "create" argv carrying the complete sandbox profile */
    public function command(string $name, string $inDir, string $outDir, int $maxPages): array
    {
        $argv = [
            $this->runtime, 'create', '--name', $name,
            '--network', 'none',
            '--user', $this->user,
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges',
            '--read-only',
            '--tmpfs', '/tmp:rw,noexec,nosuid,nodev,size=512m',
            '--pids-limit', (string) $this->pidsLimit,
            '--memory', $this->memory, '--memory-swap', $this->memory,
            '--cpus', $this->cpus,
            '--env', 'MAX_PAGES=' . $maxPages,
            '--mount', 'type=bind,source=' . $inDir . ',target=/in,readonly',
            '--mount', 'type=bind,source=' . $outDir . ',target=/out',
        ];
        if ($this->seccompProfile !== null) {
            array_push($argv, '--security-opt', 'seccomp=' . $this->seccompProfile);
        }
        if ($this->apparmorProfile !== null) {
            array_push($argv, '--security-opt', 'apparmor=' . $this->apparmorProfile);
        }
        if (basename($this->runtime) === 'podman') {
            // Rootless Podman (owner decision 7): map the container user onto the service user,
            // so the job's 0700 output directory owned by that user is writable. Without it the
            // container uid maps to a subordinate uid that cannot write there.
            [$uid, $gid] = explode(':', $this->user);
            array_push($argv, '--userns', "keep-id:uid={$uid},gid={$gid}");
        }
        $argv[] = $this->image;
        return $argv;
    }

    public function run(string $inDir, string $outDir, int $timeoutSeconds, int $maxPages): ConverterResult
    {
        foreach ([$inDir, $outDir] as $d) {
            if (!str_starts_with($d, '/') || str_contains($d, ',')) {
                throw new \InvalidArgumentException('Job directories must be absolute paths without commas');
            }
        }
        $name = 'tutora-conv-' . bin2hex(random_bytes(8));
        try {
            $created = $this->wait($this->command($name, $inDir, $outDir, $maxPages), microtime(true) + self::CREATE_TIMEOUT_SECONDS);
            if ($created === null) {
                return new ConverterResult(124, true);
            }
            if ($created !== 0) {
                return new ConverterResult(125);
            }
            $exit = $this->wait([$this->runtime, 'start', '-a', $name], microtime(true) + $timeoutSeconds);
            return $exit === null ? new ConverterResult(124, true) : new ConverterResult($exit);
        } finally {
            // killing the CLI would not stop the container: always remove it by name
            $this->ensureRemoved($name);
        }
    }

    /**
     * Runs a runtime CLI command with a deadline.
     *
     * @param list<string> $argv
     * @return int|null exit code (125 if it could not be started), null on timeout
     */
    private function wait(array $argv, float $deadline): ?int
    {
        $null = ['file', '/dev/null', 'w'];
        $proc = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes);
        if (!is_resource($proc)) {
            return 125;
        }
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                proc_close($proc);
                return (int) $status['exitcode'];
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc);
                proc_close($proc);
                return null;
            }
            usleep(100_000);
        }
    }

    /**
     * Removes the container and verifies it is gone (bounded retries). Only the "create"
     * hang guard can leave the runtime still creating it, which the retries cover.
     */
    private function ensureRemoved(string $name): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->exec([$this->runtime, 'rm', '-f', $name]);
            if ($this->exec([$this->runtime, 'container', 'inspect', $name]) !== 0) {
                return;
            }
            usleep(250_000);
        }
    }

    /** @param list<string> $argv */
    private function exec(array $argv): int
    {
        $null = ['file', '/dev/null', 'w'];
        $p = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes);
        return is_resource($p) ? proc_close($p) : -1;
    }
}
