<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tutora\Activity\AnswerRejected;
use Tutora\Activity\BlockNotOpen;
use Tutora\Activity\QuizService;
use Tutora\Block\BlockType;
use Tutora\Database\ConnectionFactory;
use Tutora\Realtime\NullBroadcaster;
use Tutora\Support\FrozenClock;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Tests\Support\Fixtures;
use Tutora\Tests\Support\LiveSession;
use Tutora\Tests\Support\TestDatabase;

final class QuizServiceTest extends TestCase
{
    private const QUESTIONS = [
        ['id' => 'q1', 'type' => 'single', 'prompt' => 'Password hash?', 'options' => ['MD5', 'Argon2id'], 'correct_answer' => 'o2', 'time_limit_seconds' => 20, 'points' => 10],
        ['id' => 'q2', 'type' => 'multi', 'prompt' => 'MFA factors?', 'options' => ['TOTP', 'Password', 'Birthday'], 'correct_answer' => ['o1', 'o2']],
        ['id' => 'q3', 'type' => 'true_false', 'prompt' => 'TLS 1.0 ok?', 'correct_answer' => false],
        ['id' => 'q4', 'type' => 'numeric', 'prompt' => 'AES block bits?', 'correct_answer' => 128, 'tolerance' => 0],
    ];

    private LiveSession $live;
    private NullBroadcaster $bc;
    private QuizService $quiz;
    private int $tutorBlock;
    private int $selfBlock;

    protected function setUp(): void
    {
        $pdo = TestDatabase::reset();
        $clock = new FrozenClock('2026-03-01 10:00:00');
        $this->bc = new NullBroadcaster();
        $this->live = new LiveSession($pdo, $clock, $this->bc, [
            [BlockType::Quiz, ['pacing' => 'tutor', 'questions' => self::QUESTIONS]],
            [BlockType::Quiz, ['pacing' => 'self', 'questions' => [
                ['id' => 's1', 'type' => 'true_false', 'prompt' => 'A', 'correct_answer' => true, 'time_limit_seconds' => 30, 'points' => 2],
                ['id' => 's2', 'type' => 'numeric', 'prompt' => 'B', 'correct_answer' => 3.14, 'tolerance' => 0.01, 'points' => 3],
            ]]],
        ]);
        $this->quiz = new QuizService($pdo, $clock, $this->bc, $this->live->participants);
        [$this->tutorBlock, $this->selfBlock] = $this->live->blocks;
    }

    private function start(string $q): void
    {
        self::assertTrue($this->quiz->startQuestion($this->live->tenantDb, $this->live->sessionId, $this->tutorBlock, $q));
    }

    public function testTutorPacedFlowAndGrading(): void
    {
        $a = $this->live->join();
        $b = $this->live->join();

        try {
            $this->quiz->answer($a, $this->tutorBlock, 'q1', ['answer' => 'o2']);
            self::fail('PENDING question must not accept answers');
        } catch (AnswerRejected) {
        }

        $this->start('q1');
        $start = end($this->bc->sent)[1];
        self::assertSame('quiz_question_start', $start['type']);
        self::assertSame('2026-03-01T10:00:20+00:00', $start['closes_at']);
        self::assertStringNotContainsString('correct_answer', json_encode($start));

        $this->quiz->answer($a, $this->tutorBlock, 'q1', ['answer' => 'o2']);
        $this->quiz->answer($b, $this->tutorBlock, 'q1', ['answer' => 'o1']);
        $count = end($this->bc->sent);
        self::assertSame('tutor', $count[2], 'live answer count goes to the tutor only');
        self::assertSame(2, $count[1]['answers']);

        // before reveal: participant sees answered=true but no key, no correctness
        $view = $this->quiz->participantView($a, $this->tutorBlock);
        self::assertSame('OPEN', $view['questions'][0]['status']);
        self::assertTrue($view['questions'][0]['answered']);
        self::assertStringNotContainsString('correct', json_encode($view));

        try {
            $this->quiz->answer($a, $this->tutorBlock, 'q1', ['answer' => 'o1']);
            self::fail('second answer must be rejected');
        } catch (AnswerRejected) {
        }

        self::assertTrue($this->quiz->reveal($this->live->tenantDb, $this->live->sessionId, $this->tutorBlock, 'q1'));
        $rev = end($this->bc->sent)[1];
        self::assertSame('quiz_question_reveal', $rev['type']);
        self::assertSame('o2', $rev['correct_answer']);
        self::assertSame(['answers' => 2, 'correct' => 1, 'distribution' => ['o1' => 1, 'o2' => 1]], $rev['results']);

        $view = $this->quiz->participantView($a, $this->tutorBlock);
        self::assertSame('REVEALED', $view['questions'][0]['status']);
        self::assertTrue($view['questions'][0]['my_correct']);
        self::assertSame(10, (int) $this->live->pdo->query("SELECT points_awarded FROM quiz_answers WHERE participant_id = {$a->participantId}")->fetchColumn());

        // multi / true_false / numeric grading
        foreach ([['q2', ['o2', 'o1'], true], ['q3', false, true], ['q4', 127, false]] as [$qid, $ans, $expected]) {
            $this->start($qid);
            $this->quiz->answer($a, $this->tutorBlock, $qid, ['answer' => $ans]);
            $this->quiz->reveal($this->live->tenantDb, $this->live->sessionId, $this->tutorBlock, $qid);
            $ok = (bool) $this->live->pdo->query("SELECT is_correct FROM quiz_answers WHERE participant_id = {$a->participantId} AND question_id = '{$qid}'")->fetchColumn();
            self::assertSame($expected, $ok, $qid);
        }
    }

    public function testAnswerAfterManualRevealRejectedEvenBeforeTimer(): void
    {
        $a = $this->live->join();
        $this->start('q1'); // 20 s timer
        $this->quiz->reveal($this->live->tenantDb, $this->live->sessionId, $this->tutorBlock, 'q1');
        $this->live->clock->advance('PT5S'); // still within the original time limit
        $this->expectException(AnswerRejected::class);
        $this->quiz->answer($a, $this->tutorBlock, 'q1', ['answer' => 'o2']);
    }

    public function testServerDeadlineEnforcedAndLazilyRevealed(): void
    {
        $a = $this->live->join();
        $this->start('q1');
        $this->live->clock->advance('PT21S');
        try {
            $this->quiz->answer($a, $this->tutorBlock, 'q1', ['answer' => 'o2']);
            self::fail('late answer accepted');
        } catch (AnswerRejected) {
        }
        self::assertSame('REVEALED', $this->live->pdo->query("SELECT status FROM quiz_question_runs WHERE question_id = 'q1'")->fetchColumn());
        self::assertSame('quiz_question_reveal', end($this->bc->sent)[1]['type']);
    }

    public function testRevealBlocksConcurrentAnswerAtomically(): void
    {
        $a = $this->live->join();
        $this->start('q2'); // no time limit

        // second connection simulates a reveal in progress holding the run row exclusively
        $other = ConnectionFactory::create(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('TEST_DB_HOST') ?: '127.0.0.1', getenv('TEST_DB_PORT') ?: '3306', getenv('TEST_DB_NAME') ?: 'tutora_test'),
            getenv('TEST_DB_USER') ?: 'tutora',
            getenv('TEST_DB_PASSWORD') ?: 'tutora_dev',
        );
        $other->beginTransaction();
        $other->query("SELECT status FROM quiz_question_runs WHERE question_id = 'q2' FOR UPDATE")->fetchColumn();

        $this->live->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->quiz->answer($a, $this->tutorBlock, 'q2', ['answer' => ['o1', 'o2']]);
            self::fail('answer proceeded while the reveal held the run row');
        } catch (\PDOException $e) {
            self::assertSame(1205, $e->errorInfo[1], 'lock wait timeout');
        }
        $other->exec("UPDATE quiz_question_runs SET status = 'REVEALED', revealed_at = UTC_TIMESTAMP(3) WHERE question_id = 'q2'");
        $other->commit();
        $this->live->pdo->exec('SET SESSION innodb_lock_wait_timeout = 50');

        $this->expectException(AnswerRejected::class);
        $this->quiz->answer($a, $this->tutorBlock, 'q2', ['answer' => ['o1', 'o2']]);
    }

    public function testOnlyOneOpenQuestionAndNoRerun(): void
    {
        $this->start('q2');
        try {
            $this->start('q3');
            self::fail('second open question allowed');
        } catch (ValidationException) {
        }
        $this->quiz->reveal($this->live->tenantDb, $this->live->sessionId, $this->tutorBlock, 'q2');
        $this->expectException(ValidationException::class);
        $this->start('q2');
    }

    public function testInvalidAnswersRejected(): void
    {
        $a = $this->live->join();
        $this->start('q2');
        foreach ([['answer' => 'o1'], ['answer' => []], ['answer' => ['zz']], ['answer' => ['o1', 'o1']], ['x' => 1]] as $bad) {
            try {
                $this->quiz->answer($a, $this->tutorBlock, 'q2', $bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (ValidationException) {
            }
        }
        self::assertSame(0, (int) $this->live->pdo->query('SELECT COUNT(*) FROM quiz_answers')->fetchColumn());
    }

    public function testTutorControlsAreTenantScoped(): void
    {
        $other = new TenantDb($this->live->pdo, TenantContext::forAuthenticatedTutor(Fixtures::tenant($this->live->pdo, 'x@example.org')));
        self::assertFalse($this->quiz->startQuestion($other, $this->live->sessionId, $this->tutorBlock, 'q1'));
        $this->start('q1');
        self::assertFalse($this->quiz->reveal($other, $this->live->sessionId, $this->tutorBlock, 'q1'));
        self::assertNull($this->quiz->tutorView($other, $this->live->sessionId, $this->tutorBlock));
        self::assertSame('OPEN', $this->quiz->tutorView($this->live->tenantDb, $this->live->sessionId, $this->tutorBlock)['questions'][0]['status']);
    }

    public function testQuizMustBeCurrentBlock(): void
    {
        $a = $this->live->join();
        $this->start('q2');
        $this->live->goTo(1);
        $this->expectException(BlockNotOpen::class);
        $this->quiz->answer($a, $this->tutorBlock, 'q2', ['answer' => ['o1']]);
    }

    public function testSelfPacedFlowReviewOnlyWhenFinished(): void
    {
        $this->live->goTo(1);
        $a = $this->live->join();
        try {
            $this->quiz->answer($a, $this->selfBlock, 's1', ['answer' => true]);
            self::fail('answer before opening accepted');
        } catch (AnswerRejected) {
        }

        $opened = $this->quiz->openQuestion($a, $this->selfBlock, 's1');
        self::assertSame('2026-03-01T10:00:30+00:00', $opened['deadline']);
        $this->live->clock->advance('PT10S');
        self::assertSame($opened, $this->quiz->openQuestion($a, $this->selfBlock, 's1'), 'reopening does not reset the timer');
        $this->quiz->answer($a, $this->selfBlock, 's1', ['answer' => true]);

        $view = $this->quiz->participantView($a, $this->selfBlock);
        self::assertFalse($view['finished']);
        self::assertArrayNotHasKey('review', $view);
        self::assertStringNotContainsString('correct', json_encode($view));

        $this->quiz->openQuestion($a, $this->selfBlock, 's2');
        $this->quiz->answer($a, $this->selfBlock, 's2', ['answer' => 3.145]);
        $view = $this->quiz->participantView($a, $this->selfBlock);
        self::assertTrue($view['finished']);
        self::assertSame(5, $view['score']);
        self::assertSame(5, $view['max_score']);
        self::assertSame(3.14, $view['review'][1]['correct_answer']);
        // no room broadcast for self-paced answers
        self::assertSame([], array_filter($this->bc->sent, static fn ($m) => str_starts_with($m[1]['type'], 'quiz') || $m[1]['type'] === 'activity_aggregate_update'));
    }

    public function testSelfPacedPerQuestionDeadline(): void
    {
        $this->live->goTo(1);
        $a = $this->live->join();
        $this->quiz->openQuestion($a, $this->selfBlock, 's1');
        $this->live->clock->advance('PT31S');
        try {
            $this->quiz->answer($a, $this->selfBlock, 's1', ['answer' => true]);
            self::fail('late self-paced answer accepted');
        } catch (AnswerRejected) {
        }
        $view = $this->quiz->participantView($a, $this->selfBlock);
        self::assertTrue($view['progress'][0]['expired']);
        self::assertFalse($view['finished'], 's2 not yet done');
    }

    public function testTutorPacedEndpointsRejectSelfPacedBlockAndViceVersa(): void
    {
        $a = $this->live->join();
        try {
            $this->quiz->openQuestion($a, $this->tutorBlock, 'q1');
            self::fail('self-paced open on tutor-paced quiz');
        } catch (BlockNotOpen) {
        }
        $this->live->goTo(1);
        $this->expectException(BlockNotOpen::class);
        $this->quiz->startQuestion($this->live->tenantDb, $this->live->sessionId, $this->selfBlock, 's1');
    }
}
