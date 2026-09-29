<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tutora\Security\Logger;
use Tutora\Security\RateLimiter;
use Tutora\Slides\ConversionWorker;
use Tutora\Slides\ConverterResult;
use Tutora\Slides\SlideImportService;
use Tutora\Slides\SlideStorage;
use Tutora\Support\FrozenClock;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Tests\Support\FakeConverterRunner;
use Tutora\Tests\Support\Fixtures;
use Tutora\Tests\Support\TestDatabase;

final class SlidePipelineTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private string $root;
    private SlideStorage $storage;
    private SlideImportService $imports;
    private FakeConverterRunner $runner;
    private ConversionWorker $worker;
    private int $tenant;
    private int $workshop;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-03-01 10:00:00');
        $this->root = sys_get_temp_dir() . '/tutora-test-' . bin2hex(random_bytes(4));
        $this->storage = new SlideStorage($this->root);
        $this->tenant = Fixtures::tenant($this->pdo, 'a@example.org');
        $this->workshop = Fixtures::workshop($this->pdo, $this->tenant);
        $this->imports = new SlideImportService(new TenantDb($this->pdo, TenantContext::forAuthenticatedTutor($this->tenant)), $this->storage, new RateLimiter($this->pdo, $this->clock), $this->clock, 1 << 20);
        $this->runner = new FakeConverterRunner();
        $this->worker = new ConversionWorker($this->pdo, $this->storage, $this->runner, $this->clock, new Logger(static fn () => null));
    }

    protected function tearDown(): void
    {
        SlideStorage::removeTree($this->root);
    }

    private function upload(string $fixture = 'sample.pptx', string $name = 'Deck.pptx'): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        copy(__DIR__ . '/../fixtures/' . $fixture, $tmp);
        return (int) $this->imports->upload($this->workshop, $tmp, $name);
    }

    private function job(): array
    {
        return $this->pdo->query('SELECT * FROM conversion_jobs ORDER BY id DESC LIMIT 1')->fetch();
    }

    public function testUploadStagesFileOutsideWebRootAndQueuesJob(): void
    {
        $import = $this->upload();
        $job = $this->job();
        self::assertSame('pending', $job['status']);
        self::assertStringStartsWith($this->root . '/staging/', $job['source_path']);
        self::assertStringEndsWith('.pptx', $job['source_path']);
        self::assertFileExists($job['source_path']);
        self::assertSame('0640', substr(sprintf('%o', fileperms($job['source_path'])), -4));
        self::assertSame($import, (int) $job['slide_import_id']);
        self::assertSame('Deck.pptx', $this->pdo->query("SELECT original_filename FROM slide_imports WHERE id = {$import}")->fetchColumn());
    }

    public function testUploadToOtherTenantsWorkshopRefused(): void
    {
        $other = new SlideImportService(new TenantDb($this->pdo, TenantContext::forAuthenticatedTutor(Fixtures::tenant($this->pdo, 'b@example.org'))), $this->storage, new RateLimiter($this->pdo, $this->clock), $this->clock, 1 << 20);
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        copy(__DIR__ . '/../fixtures/sample.pdf', $tmp);
        self::assertNull($other->upload($this->workshop, $tmp, 'x.pdf'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM conversion_jobs')->fetchColumn());
        self::assertSame([], $other->imports($this->workshop));
    }

    public function testUploadRateLimit(): void
    {
        for ($i = 0; $i < SlideImportService::UPLOADS_PER_HOUR; $i++) {
            $this->upload('sample.pdf', 'x.pdf');
        }
        $this->expectException(ValidationException::class);
        $this->upload('sample.pdf', 'x.pdf');
    }

    public function testSuccessfulConversionPersistsAssetsAndCleansUp(): void
    {
        $import = $this->upload();
        $source = $this->job()['source_path'];
        self::assertNotNull($this->worker->processNext());
        self::assertSame('source.pptx', $this->runner->seenSource, 'converter sees a fixed file name, never the uploaded name');

        $job = $this->job();
        self::assertSame('done', $job['status']);
        self::assertFileDoesNotExist($source, 'original deleted after successful conversion');
        self::assertSame([], array_diff(scandir($this->root . '/jobs'), ['.', '..']), 'job dir removed');
        self::assertSame(2, (int) $this->pdo->query("SELECT page_count FROM slide_imports WHERE id = {$import}")->fetchColumn());
        $assets = $this->pdo->query('SELECT * FROM slide_assets ORDER BY page_number')->fetchAll();
        self::assertSame([1, 2], array_map('intval', array_column($assets, 'page_number')));
        self::assertSame(16, (int) $assets[0]['width']);
        self::assertSame($this->root . "/slides/{$this->tenant}/{$import}/1.png", $assets[0]['image_path']);
        self::assertNull($this->worker->processNext(), 'queue empty');
        self::assertCount(1, $this->imports->imports($this->workshop));
    }

    public function testConverterFailureKeepsSourceAtMost24h(): void
    {
        $this->upload();
        $source = $this->job()['source_path'];
        $this->runner->behaviour = static fn () => new ConverterResult(4);
        $this->worker->processNext();
        $job = $this->job();
        self::assertSame('failed', $job['status']);
        self::assertSame('The presentation could not be converted.', $job['error_message']);
        self::assertSame('2026-03-02 10:00:00.000', $job['source_purge_after']);
        self::assertFileExists($source);
        self::assertSame(1, $this->runner->calls, 'deterministic failures are not retried');

        $this->clock->advance('PT23H');
        self::assertSame(0, $this->worker->purgeFailedSources());
        $this->clock->advance('PT1H');
        self::assertSame(1, $this->worker->purgeFailedSources());
        self::assertFileDoesNotExist($source);
    }

    public function testTimeoutAndTooManyPagesMessages(): void
    {
        $this->upload();
        $this->runner->behaviour = static fn () => new ConverterResult(124, true);
        $this->worker->processNext();
        self::assertSame('Conversion took too long.', $this->job()['error_message']);

        $this->upload();
        $this->runner->behaviour = static fn () => new ConverterResult(3);
        $this->worker->processNext();
        self::assertSame('The presentation has too many pages.', $this->job()['error_message']);
    }

    public function testRuntimeUnavailableIsRetriedThenFails(): void
    {
        $this->upload();
        $this->runner->behaviour = static fn () => new ConverterResult(125);
        for ($i = 0; $i < 5; $i++) {
            $this->worker->processNext();
        }
        self::assertSame(ConversionWorker::MAX_ATTEMPTS, $this->runner->calls);
        self::assertSame('failed', $this->job()['status']);
    }

    /** @return iterable<string,array{callable}> */
    public static function hostileOutputs(): iterable
    {
        yield 'symlink to host file' => [static function (string $in, string $out): ConverterResult {
            symlink('/etc/passwd', "{$out}/page-1.png");
            return new ConverterResult(0);
        }];
        yield 'not a png' => [static function (string $in, string $out): ConverterResult {
            file_put_contents("{$out}/page-1.png", '<svg onload=alert(1)>');
            return new ConverterResult(0);
        }];
        yield 'unexpected file' => [static function (string $in, string $out): ConverterResult {
            copy(__DIR__ . '/../fixtures/page.png', "{$out}/page-1.png");
            file_put_contents("{$out}/evil.php", '<?php system($_GET[1]);');
            return new ConverterResult(0);
        }];
        yield 'gap in pages' => [static function (string $in, string $out): ConverterResult {
            copy(__DIR__ . '/../fixtures/page.png', "{$out}/page-1.png");
            copy(__DIR__ . '/../fixtures/page.png', "{$out}/page-3.png");
            return new ConverterResult(0);
        }];
        yield 'directory named like a page' => [static function (string $in, string $out): ConverterResult {
            mkdir("{$out}/page-1.png");
            return new ConverterResult(0);
        }];
        yield 'no output' => [static fn () => new ConverterResult(0)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileOutputs')]
    public function testHostileConverterOutputRejected(callable $behaviour): void
    {
        $this->upload();
        $this->runner->behaviour = $behaviour;
        $this->worker->processNext();
        self::assertSame('failed', $this->job()['status']);
        self::assertSame('The converted slides failed validation.', $this->job()['error_message']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM slide_assets')->fetchColumn());
        self::assertDirectoryDoesNotExist($this->root . "/slides/{$this->tenant}/1/evil.php");
    }

    public function testTooManyPagesInOutputRejected(): void
    {
        $worker = new ConversionWorker($this->pdo, $this->storage, $this->runner, $this->clock, new Logger(static fn () => null), 120, 2);
        $this->upload();
        $this->runner->behaviour = FakeConverterRunner::pages(3);
        $worker->processNext();
        self::assertSame('failed', $this->job()['status']);
    }

    public function testStaleRunningJobRecovered(): void
    {
        $this->upload();
        $this->pdo->exec("UPDATE conversion_jobs SET status = 'running', attempts = 1, locked_at = '2026-03-01 09:00:00'");
        self::assertSame(1, $this->worker->recoverStale());
        self::assertSame('pending', $this->job()['status']);
        $this->pdo->exec("UPDATE conversion_jobs SET status = 'running', attempts = 3, locked_at = '2026-03-01 09:00:00'");
        $this->worker->recoverStale();
        self::assertSame('failed', $this->job()['status']);
    }

    public function testConcurrentWorkersDoNotClaimTheSameJob(): void
    {
        $this->upload();
        $other = \Tutora\Database\ConnectionFactory::create(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('TEST_DB_HOST') ?: '127.0.0.1', getenv('TEST_DB_PORT') ?: '3306', getenv('TEST_DB_NAME') ?: 'tutora_test'),
            getenv('TEST_DB_USER') ?: 'tutora',
            getenv('TEST_DB_PASSWORD') ?: 'tutora_dev',
        );
        $other->beginTransaction();
        $other->query("SELECT id FROM conversion_jobs WHERE status = 'pending' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
        // the row is locked by the other worker: this worker skips it instead of waiting
        self::assertNull($this->worker->processNext());
        $other->rollBack();
        self::assertNotNull($this->worker->processNext());
    }
}
