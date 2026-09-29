<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tutora\Http\Request;
use Tutora\Http\UploadedFile;
use Tutora\Security\Logger;
use Tutora\Slides\ConversionWorker;
use Tutora\Slides\SlideStorage;
use Tutora\Support\FrozenClock;
use Tutora\Tests\Support\FakeConverterRunner;
use Tutora\Tests\Support\HttpHarness;
use Tutora\Tests\Support\TestDatabase;

final class SlideHttpTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private HttpHarness $tutor;
    private int $wid;

    protected function setUp(): void
    {
        SlideStorage::removeTree(sys_get_temp_dir() . '/tutora-http-test-storage');
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-03-01 10:00:00');
        $this->tutor = new HttpHarness($this->pdo, $this->clock);
        $this->tutor->signUpTutor('tutor@example.org');
        $this->wid = (int) basename($this->tutor->post('/workshops', ['title' => 'Slides'])->headers['Location']);
    }

    private function uploadAs(HttpHarness $h, int $wid, string $fixture, string $name): \Tutora\Http\Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        copy(__DIR__ . '/../fixtures/' . $fixture, $tmp);
        $h->get("/workshops/{$this->wid}");
        return $h->app->handle(new Request('POST', "/workshops/{$wid}/slides",
            post: ['_csrf' => (string) $h->session->get('_csrf')], headers: ['origin' => 'https://tutora.test'],
            clientIp: $h->ip, files: ['deck' => new UploadedFile($tmp, $name, UPLOAD_ERR_OK)]));
    }

    private function convert(): void
    {
        (new ConversionWorker($this->pdo, new SlideStorage(sys_get_temp_dir() . '/tutora-http-test-storage'), new FakeConverterRunner(), $this->clock, new Logger(static fn () => null)))->processNext();
    }

    private function assetIds(): array
    {
        return array_map('intval', $this->pdo->query('SELECT id FROM slide_assets ORDER BY page_number')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testUploadConvertAddAllAndServe(): void
    {
        self::assertSame(303, $this->uploadAs($this->tutor, $this->wid, 'sample.pptx', 'deck.pptx')->status);
        self::assertStringContainsString('Converting', $this->tutor->get("/workshops/{$this->wid}")->body);
        $this->convert();
        $page = $this->tutor->get("/workshops/{$this->wid}")->body;
        self::assertStringContainsString('2 pages', $page);

        $import = (int) $this->pdo->query('SELECT id FROM slide_imports')->fetchColumn();
        $this->tutor->post("/workshops/{$this->wid}/slides/{$import}/add-all");
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM workshop_blocks WHERE block_type = 'slide'")->fetchColumn());

        $img = $this->tutor->get('/slides/' . $this->assetIds()[0]);
        self::assertSame(200, $img->status);
        self::assertSame('image/png', $img->headers['Content-Type']);
        self::assertStringStartsWith("\x89PNG", $img->body);
        self::assertStringContainsString('sandbox', $img->headers['Content-Security-Policy']);
        self::assertSame('nosniff', $img->headers['X-Content-Type-Options']);
    }

    public function testInvalidUploadShowsErrorAndQueuesNothing(): void
    {
        $r = $this->uploadAs($this->tutor, $this->wid, 'page.png', 'slides.pptx');
        self::assertSame(422, $r->status);
        self::assertStringContainsString('Only PDF and PowerPoint', $r->body);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM conversion_jobs')->fetchColumn());
    }

    public function testImageAuthorisation(): void
    {
        $this->uploadAs($this->tutor, $this->wid, 'sample.pdf', 'deck.pdf');
        $this->convert();
        [$a1, $a2] = $this->assetIds();

        $other = new HttpHarness($this->pdo, $this->clock, '198.51.100.99');
        $other->signUpTutor('other@example.org');
        self::assertSame(404, $other->get("/slides/{$a1}")->status, 'other tenant');
        self::assertSame(404, $this->uploadAs($other, $this->wid, 'sample.pdf', 'x.pdf')->status, 'upload to other tenant workshop');
        self::assertSame('/login', (new HttpHarness($this->pdo, $this->clock, '198.51.100.50'))->get("/slides/{$a1}")->headers['Location']);

        // session with only page 1 as a slide block
        $this->tutor->post("/workshops/{$this->wid}/blocks", ['block_type' => 'slide', 'config' => '{}', 'slide_asset_id' => (string) $a1]);
        $sid = (int) basename($this->tutor->post("/workshops/{$this->wid}/sessions")->headers['Location']);
        $p = new HttpHarness($this->pdo, $this->clock, '203.0.113.9');
        $code = (string) $this->pdo->query("SELECT join_code FROM sessions WHERE id = {$sid}")->fetchColumn();
        $cred = HttpHarness::json($p->api('POST', '/api/participant/join', ['code' => $code]))['credential'];

        self::assertSame(200, $p->api('GET', "/api/participant/sessions/{$sid}/slides/{$a1}", bearer: $cred)->status);
        self::assertSame(404, $p->api('GET', "/api/participant/sessions/{$sid}/slides/{$a2}", bearer: $cred)->status, 'asset not used in this session');
        self::assertSame(401, $p->api('GET', "/api/participant/sessions/{$sid}/slides/{$a1}")->status);
        $state = HttpHarness::json($p->api('GET', "/api/participant/sessions/{$sid}/state", bearer: $cred));
        self::assertSame($a1, $state['current_block']['slide_asset_id']);
    }

    public function testDeletingWorkshopRemovesSlideFiles(): void
    {
        $this->uploadAs($this->tutor, $this->wid, 'sample.pdf', 'deck.pdf');
        $this->convert();
        $path = (string) $this->pdo->query('SELECT image_path FROM slide_assets LIMIT 1')->fetchColumn();
        self::assertFileExists($path);
        $this->uploadAs($this->tutor, $this->wid, 'sample.pdf', 'pending.pdf');
        $staged = (string) $this->pdo->query("SELECT source_path FROM conversion_jobs WHERE status = 'pending'")->fetchColumn();
        self::assertFileExists($staged);

        self::assertSame(303, $this->tutor->post("/workshops/{$this->wid}/delete")->status);
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($staged);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM slide_assets')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM slide_imports')->fetchColumn());
    }

    /** Owner decision 8: images stay while a past session uses them, and go with the last one. */
    public function testSlidesUsedByPastSessionsSurviveWorkshopDeletion(): void
    {
        $this->uploadAs($this->tutor, $this->wid, 'sample.pdf', 'used.pdf');
        $this->convert();
        $this->uploadAs($this->tutor, $this->wid, 'sample.pdf', 'unused.pdf');
        $this->convert();
        $used = (int) $this->pdo->query("SELECT id FROM slide_imports WHERE original_filename = 'used.pdf'")->fetchColumn();
        [$a1] = array_map('intval', $this->pdo->query("SELECT id FROM slide_assets WHERE slide_import_id = {$used} ORDER BY page_number")->fetchAll(PDO::FETCH_COLUMN));
        $usedPaths = $this->pdo->query("SELECT image_path FROM slide_assets WHERE slide_import_id = {$used}")->fetchAll(PDO::FETCH_COLUMN);
        $unusedPaths = $this->pdo->query("SELECT image_path FROM slide_assets WHERE slide_import_id <> {$used}")->fetchAll(PDO::FETCH_COLUMN);

        $this->tutor->post("/workshops/{$this->wid}/blocks", ['block_type' => 'slide', 'config' => '{}', 'slide_asset_id' => (string) $a1]);
        $s1 = (int) basename($this->tutor->post("/workshops/{$this->wid}/sessions")->headers['Location']);
        $this->tutor->post("/sessions/{$s1}/end");
        $s2 = (int) basename($this->tutor->post("/workshops/{$this->wid}/sessions")->headers['Location']);
        $this->tutor->post("/sessions/{$s2}/end");

        // another tenant with a detached, unused import must not be touched by our clean-up
        $other = new HttpHarness($this->pdo, $this->clock, '198.51.100.99');
        $other->signUpTutor('other@example.org');
        $this->pdo->exec("INSERT INTO slide_imports (tenant_id, workshop_id, original_filename, page_count, created_at)
                          SELECT id, NULL, 'foreign.pdf', 0, NOW(3) FROM tenants WHERE email = 'other@example.org'");

        self::assertSame(303, $this->tutor->post("/workshops/{$this->wid}/delete")->status);
        foreach ($usedPaths as $f) {
            self::assertFileExists($f, 'image used by past sessions is kept');
        }
        foreach ($unusedPaths as $f) {
            self::assertFileDoesNotExist($f, 'unused import removed with the workshop');
        }
        self::assertSame(null, $this->pdo->query("SELECT workshop_id FROM slide_imports WHERE id = {$used}")->fetchColumn(), 'import detached');
        self::assertSame(200, $this->tutor->get("/slides/{$a1}")->status, 'tutor can still view it (session history)');
        self::assertSame(404, $other->get("/slides/{$a1}")->status, 'still tenant-scoped');
        self::assertSame($a1, (int) $this->pdo->query("SELECT slide_asset_id FROM session_blocks WHERE session_id = {$s1}")->fetchColumn());

        self::assertSame(303, $this->tutor->post("/sessions/{$s1}/delete")->status);
        foreach ($usedPaths as $f) {
            self::assertFileExists($f, 'second session still uses it');
        }
        self::assertSame(303, $this->tutor->post("/sessions/{$s2}/delete")->status);
        foreach ($usedPaths as $f) {
            self::assertFileDoesNotExist($f, 'removed with the last session that used it');
        }
        self::assertSame(['foreign.pdf'], $this->pdo->query('SELECT original_filename FROM slide_imports')->fetchAll(PDO::FETCH_COLUMN));
    }
}
