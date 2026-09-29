<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tutora\Slides\DockerConverterRunner;
use Tutora\Slides\SlideStorage;

/**
 * Runs the real converter image with the full sandbox profile. Skipped unless
 * TUTORA_TEST_CONVERTER_IMAGE names a locally available image (see converter/Dockerfile).
 */
final class DockerConverterRunnerTest extends TestCase
{
    private string $dir;
    private string $image;

    protected function setUp(): void
    {
        $this->image = (string) getenv('TUTORA_TEST_CONVERTER_IMAGE');
        if ($this->image === '') {
            self::markTestSkipped('TUTORA_TEST_CONVERTER_IMAGE not set');
        }
        $this->dir = sys_get_temp_dir() . '/tutora-docker-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/in', 0755, true);
        mkdir($this->dir . '/out', 0777, true);
        chmod($this->dir . '/out', 0777);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            SlideStorage::removeTree($this->dir);
        }
    }

    public function testCommandCarriesSandboxProfile(): void
    {
        $argv = (new DockerConverterRunner($this->image))->command('n', '/a', '/b', 10);
        $cmd = implode(' ', $argv);
        foreach (['--network none', '--user 65532:65532', '--cap-drop ALL', '--security-opt no-new-privileges', '--read-only',
                  '--pids-limit 256', '--memory 1g', '--memory-swap 1g', '--cpus 1', 'target=/in,readonly', 'noexec'] as $flag) {
            self::assertStringContainsString($flag, $cmd, $flag);
        }
    }

    public function testRootUserRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DockerConverterRunner($this->image, 'docker', '0:0');
    }

    public function testConvertsPptxInSandbox(): void
    {
        copy(__DIR__ . '/../fixtures/sample.pptx', $this->dir . '/in/source.pptx');
        chmod($this->dir . '/in/source.pptx', 0644);
        $r = (new DockerConverterRunner($this->image))->run($this->dir . '/in', $this->dir . '/out', 120, 50);
        self::assertSame(0, $r->exitCode);
        self::assertSame(['page-1.png', 'page-2.png', 'page-3.png'], array_values(array_diff(scandir($this->dir . '/out'), ['.', '..'])));
    }

    public function testWallClockTimeoutRemovesContainer(): void
    {
        copy(__DIR__ . '/../fixtures/sample.pptx', $this->dir . '/in/source.pptx');
        chmod($this->dir . '/in/source.pptx', 0644);
        $start = microtime(true);
        $r = (new DockerConverterRunner($this->image))->run($this->dir . '/in', $this->dir . '/out', 1, 50);
        self::assertTrue($r->timedOut);
        self::assertLessThan(15, microtime(true) - $start);
        $left = shell_exec("docker ps -a --filter name=tutora-conv- --format '{{.Names}}'");
        self::assertSame('', trim((string) $left), 'no converter container left running');
    }
}
