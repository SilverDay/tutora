<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tutora\Activity\QuizService;
use Tutora\Activity\SubmissionService;
use Tutora\Activity\WallService;
use Tutora\Block\BlockType;
use Tutora\Export\SessionExporter;
use Tutora\Realtime\NullBroadcaster;
use Tutora\Support\FrozenClock;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Tests\Support\Fixtures;
use Tutora\Tests\Support\LiveSession;
use Tutora\Tests\Support\TestDatabase;

final class SessionExportTest extends TestCase
{
    /** @return list<list<string>> */
    private static function parse(string $csv): array
    {
        self::assertStringStartsWith("\u{FEFF}", $csv);
        $rows = [];
        $h = fopen('php://memory', 'r+');
        fwrite($h, substr($csv, 3));
        rewind($h);
        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            $rows[] = $r;
        }
        return $rows;
    }

    public function testUnionOfAllAnswerShapesWithPseudonymsAndLabels(): void
    {
        $pdo = TestDatabase::reset();
        $clock = new FrozenClock('2026-03-01 10:00:00');
        $bc = new NullBroadcaster();
        $live = new LiveSession($pdo, $clock, $bc, [
            [BlockType::Poll, ['question' => 'Ready?', 'options' => ['Yes', 'No']]],
            [BlockType::Write, ['prompt' => 'Thoughts?']],
            [BlockType::Rate, ['items' => ['Pace', 'Clarity'], 'scale' => 5]],
            [BlockType::Quiz, ['pacing' => 'tutor', 'questions' => [['type' => 'single', 'prompt' => 'Best hash?', 'options' => ['MD5', 'Argon2id'], 'correct_answer' => 'o2', 'points' => 2]]]],
            [BlockType::Wall, ['columns' => ['Good', 'Improve']]],
        ]);
        $subs = new SubmissionService($pdo, $clock, $bc, $live->participants);
        $quiz = new QuizService($pdo, $clock, $bc, $live->participants);
        $wall = new WallService($pdo, $clock, $bc, $live->participants);
        $code = (string) $live->sessions->find($live->sessionId)['join_code'];
        $alice = $live->participants->authenticate($live->participants->join($code, 'Alice Secretname', '198.51.100.20')['credential'], $live->sessionId);
        $bob = $live->join();
        [$poll, $write, $rate, $qz, $wl] = $live->blocks;

        $subs->submit($alice, $poll, ['selected' => ['o1']]);
        $subs->submit($bob, $poll, ['selected' => ['o2']]);
        $live->goTo(1);
        $subs->submit($alice, $write, ['text' => '=HYPERLINK("http://evil.example","click")']);
        $live->goTo(2);
        $subs->submit($bob, $rate, ['ratings' => ['i1' => 4, 'i2' => 5]]);
        $live->goTo(3);
        $quiz->startQuestion($live->tenantDb, $live->sessionId, $qz, 'q1');
        $quiz->answer($alice, $qz, 'q1', ['answer' => 'o2']);
        $live->goTo(4);
        $wall->addCard($bob, $wl, 'More breaks', 'c2');
        $wall->tutorAddCard($live->tenantDb, $live->sessionId, $wl, 'Tutor note', 'c1');

        $result = (new SessionExporter($live->tenantDb))->export($live->sessionId);
        $csv = $result['csv'];
        self::assertStringNotContainsString('Secretname', $csv, 'display names never exported');
        $rows = self::parse($csv);
        self::assertSame(SessionExporter::HEADER, $rows[0]);
        $body = array_map(static fn ($r) => array_combine(SessionExporter::HEADER, $r), array_slice($rows, 1));
        self::assertCount(8, $body);
        self::assertSame($result['rows'], count($body));

        $by = static fn (string $type) => array_values(array_filter($body, static fn ($r) => $r['block_type'] === $type));
        self::assertSame([['P1', 'Yes'], ['P2', 'No']], array_map(static fn ($r) => [$r['participant'], $r['answer']], $by('poll')));
        self::assertSame('Ready?', $by('poll')[0]['block_prompt']);
        self::assertSame('\'=HYPERLINK("http://evil.example","click")', $by('write')[0]['answer'], 'formula neutralised');
        self::assertSame([['Pace', '4'], ['Clarity', '5']], array_map(static fn ($r) => [$r['item'], $r['answer']], $by('rate')));
        $q = $by('quiz')[0];
        self::assertSame(['Best hash?', 'P1', 'q1', 'Argon2id', 'true', '2'], [$q['block_prompt'], $q['participant'], $q['item'], $q['answer'], $q['is_correct'], $q['points']]);
        self::assertEqualsCanonicalizing([['P2', 'Improve', 'More breaks'], ['Tutor', 'Good', 'Tutor note']],
            array_map(static fn ($r) => [$r['participant'], $r['item'], $r['answer']], $by('wall')));
        self::assertSame(['1', '2', '3', '4', '5'], array_values(array_unique(array_column($body, 'block_position'))), 'ordered by block');

        $other = new TenantDb($pdo, TenantContext::forAuthenticatedTutor(Fixtures::tenant($pdo, 'other@example.org')));
        self::assertNull((new SessionExporter($other))->export($live->sessionId), 'tenant-scoped');
    }
}
