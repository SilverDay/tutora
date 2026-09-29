<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tutora\Block\BlockType;
use Tutora\Support\FrozenClock;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Tests\Support\Fixtures;
use Tutora\Tests\Support\TestDatabase;
use Tutora\Workshop\WorkshopRepository;

final class WorkshopRepositoryTest extends TestCase
{
    private PDO $pdo;
    private WorkshopRepository $a;
    private WorkshopRepository $b;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $clock = new FrozenClock();
        $ta = Fixtures::tenant($this->pdo, 'a@example.org');
        $tb = Fixtures::tenant($this->pdo, 'b@example.org');
        $this->a = new WorkshopRepository(new TenantDb($this->pdo, TenantContext::forAuthenticatedTutor($ta)), $clock);
        $this->b = new WorkshopRepository(new TenantDb($this->pdo, TenantContext::forAuthenticatedTutor($tb)), $clock);
    }

    public function testCrudAndOrdering(): void
    {
        $w = $this->a->create('Intro', null);
        $b1 = $this->a->addBlock($w, BlockType::Write, ['prompt' => 'one']);
        $b2 = $this->a->addBlock($w, BlockType::Write, ['prompt' => 'two']);
        $b3 = $this->a->addBlock($w, BlockType::Write, ['prompt' => 'three']);
        self::assertSame([$b1, $b2, $b3], array_map(static fn ($b) => (int) $b['id'], $this->a->blocks($w)));

        $this->a->moveBlock($b3, 0);
        self::assertSame([$b3, $b1, $b2], array_map(static fn ($b) => (int) $b['id'], $this->a->blocks($w)));
        self::assertSame([0, 1, 2], array_map(static fn ($b) => (int) $b['position'], $this->a->blocks($w)));

        $this->a->deleteBlock($b1);
        self::assertSame([$b3, $b2], array_map(static fn ($b) => (int) $b['id'], $this->a->blocks($w)));
        self::assertSame([0, 1], array_map(static fn ($b) => (int) $b['position'], $this->a->blocks($w)));

        $this->a->updateBlock($b2, ['prompt' => 'changed']);
        self::assertSame('changed', $this->a->findBlock($b2)['config']['prompt']);
        self::assertSame(1, (int) $this->a->findBlock($b2)['config_version']);

        self::assertTrue($this->a->delete($w));
        self::assertSame([], $this->a->list());
    }

    public function testInvalidConfigNotStored(): void
    {
        $w = $this->a->create('W', null);
        try {
            $this->a->addBlock($w, BlockType::Poll, ['question' => 'q', 'options' => ['only one']]);
            self::fail('expected validation error');
        } catch (ValidationException) {
        }
        self::assertSame([], $this->a->blocks($w));
    }

    public function testSlideBlockRequiresOwnAsset(): void
    {
        $w = $this->a->create('W', null);
        $this->expectException(ValidationException::class);
        $this->a->addBlock($w, BlockType::Slide, []);
    }

    public function testCrossTenantAccessFails(): void
    {
        $w = $this->a->create('Secret workshop', 'desc');
        $blk = $this->a->addBlock($w, BlockType::Write, ['prompt' => 'p']);

        self::assertNull($this->b->find($w));
        self::assertSame([], $this->b->list());
        self::assertSame([], $this->b->blocks($w));
        self::assertNull($this->b->findBlock($blk));
        self::assertFalse($this->b->update($w, 'pwned', null));
        self::assertNull($this->b->addBlock($w, BlockType::Write, ['prompt' => 'injected']));
        self::assertFalse($this->b->updateBlock($blk, ['prompt' => 'pwned']));
        self::assertFalse($this->b->moveBlock($blk, 5));
        self::assertFalse($this->b->deleteBlock($blk));
        self::assertFalse($this->b->delete($w));

        self::assertSame('Secret workshop', $this->a->find($w)['title']);
        self::assertCount(1, $this->a->blocks($w));
        self::assertSame('p', $this->a->findBlock($blk)['config']['prompt']);
    }

    public function testCrossTenantSlideAssetRejected(): void
    {
        $wa = $this->a->create('A', null);
        $wb = $this->b->create('B', null);
        // an asset belonging to tenant B's workshop
        $tb = (int) $this->pdo->query("SELECT tenant_id FROM workshops WHERE id = {$wb}")->fetchColumn();
        $this->pdo->exec("INSERT INTO slide_imports (tenant_id, workshop_id, original_filename, page_count, created_at) VALUES ({$tb}, {$wb}, 'b.pdf', 1, UTC_TIMESTAMP(3))");
        $imp = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO slide_assets (slide_import_id, page_number, image_path, width, height) VALUES ({$imp}, 1, 'x.png', 100, 100)");
        $asset = (int) $this->pdo->lastInsertId();

        $this->expectException(ValidationException::class);
        $this->a->addBlock($wa, BlockType::Slide, [], $asset);
    }

    public function testDeleteWorkshopWithSlideBlocks(): void
    {
        $w = $this->a->create('A', null);
        $t = (int) $this->pdo->query("SELECT tenant_id FROM workshops WHERE id = {$w}")->fetchColumn();
        $this->pdo->exec("INSERT INTO slide_imports (tenant_id, workshop_id, original_filename, page_count, created_at) VALUES ({$t}, {$w}, 'a.pdf', 1, UTC_TIMESTAMP(3))");
        $imp = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO slide_assets (slide_import_id, page_number, image_path, width, height) VALUES ({$imp}, 1, 'x.png', 100, 100)");
        $this->a->addBlock($w, BlockType::Slide, [], (int) $this->pdo->lastInsertId());
        self::assertTrue($this->a->delete($w));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM slide_assets')->fetchColumn());
    }

    public function testTitleValidation(): void
    {
        $this->expectException(ValidationException::class);
        $this->a->create('   ', null);
    }
}
