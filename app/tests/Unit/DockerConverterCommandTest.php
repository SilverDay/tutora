<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Slides\DockerConverterRunner;

final class DockerConverterCommandTest extends TestCase
{
    /** Rootless Podman: the container user is mapped onto the service user (writable job dirs). */
    public function testPodmanMapsContainerUserOntoServiceUser(): void
    {
        $podman = (new DockerConverterRunner('img', '/usr/bin/podman'))->command('n', '/a', '/b', 10);
        $i = array_search('--userns', $podman, true);
        self::assertNotFalse($i);
        self::assertSame('keep-id:uid=65532,gid=65532', $podman[$i + 1]);
        self::assertSame('img', end($podman), 'image stays the last argument');
        self::assertContains('--network', $podman, 'sandbox profile unchanged');
        self::assertNotContains('--userns', (new DockerConverterRunner('img'))->command('n', '/a', '/b', 10), 'docker: unchanged');
    }
}
