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
 * The command is an argv array (no shell). Container output is discarded so document
 * content never reaches logs.
 */
final class DockerConverterRunner implements ConverterRunner
{
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

    /** @return list<string> */
    public function command(string $name, string $inDir, string $outDir, int $maxPages): array
    {
        $argv = [
            $this->runtime, 'run', '--rm', '--name', $name,
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
        $null = ['file', '/dev/null', 'w'];
        $proc = proc_open($this->command($name, $inDir, $outDir, $maxPages), [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes);
        if (!is_resource($proc)) {
            return new ConverterResult(125);
        }
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                proc_close($proc);
                return new ConverterResult((int) $status['exitcode']);
            }
            if (microtime(true) >= $deadline) {
                // killing the CLI would not stop the container: remove it by name
                $kill = proc_open([$this->runtime, 'rm', '-f', $name], [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $p2);
                if (is_resource($kill)) {
                    proc_close($kill);
                }
                proc_terminate($proc);
                proc_close($proc);
                return new ConverterResult(124, true);
            }
            usleep(100_000);
        }
    }
}
