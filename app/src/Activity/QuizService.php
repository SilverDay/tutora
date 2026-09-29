<?php

declare(strict_types=1);

namespace Tutora\Activity;

use DateTimeImmutable;
use PDO;
use Tutora\Database\Transaction;
use Tutora\Participant\ParticipantContext;
use Tutora\Participant\ParticipantService;
use Tutora\Realtime\Broadcaster;
use Tutora\Support\Clock;
use Tutora\Support\Time;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantDb;

/**
 * Quiz subsystem (spec: Quiz Subsystem).
 *
 * Tutor-paced: PENDING (no run row) -> OPEN -> REVEALED, one quiz_question_runs row per
 * question. An answer is accepted only while the run is OPEN and before its server-side
 * deadline; the answer takes a shared lock on the run row and a reveal takes an exclusive
 * lock and flips the status in the same transaction, so no answer can be accepted after
 * a reveal commits. Expired runs are revealed lazily on the next read/answer.
 *
 * Self-paced: one quiz_participant_question_starts row per participant/question; each
 * question has its own server-side deadline; results are returned only once the
 * participant has finished the whole quiz.
 *
 * The answer key (correct_answer, tolerance) never reaches a participant before reveal.
 */
final class QuizService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly Broadcaster $broadcaster,
        private readonly ParticipantService $participants,
    ) {
    }

    // ---------------------------------------------------------------- tutor-paced

    /** @throws ValidationException|BlockNotOpen */
    public function startQuestion(TenantDb $tenant, int $sessionId, int $blockId, string $questionId): bool
    {
        if (!$this->ownsSession($tenant, $sessionId)) {
            return false;
        }
        $this->expireDue($sessionId);
        $now = $this->clock->now();
        $run = Transaction::run($this->pdo, function (PDO $pdo) use ($sessionId, $blockId, $questionId, $now): array {
            $config = $this->lockQuiz($pdo, $sessionId, $blockId, 'tutor', true);
            $q = self::question($config, $questionId) ?? throw new ValidationException(['Unknown question.']);
            $open = $pdo->prepare("SELECT question_id FROM quiz_question_runs WHERE session_id = ? AND session_block_id = ? AND status = 'OPEN' FOR UPDATE");
            $open->execute([$sessionId, $blockId]);
            if ($open->fetchColumn() !== false) {
                throw new ValidationException(['Reveal the open question before starting another.']);
            }
            $closes = isset($q['time_limit_seconds']) ? $now->modify('+' . (int) $q['time_limit_seconds'] . ' seconds') : null;
            try {
                $pdo->prepare(
                    "INSERT INTO quiz_question_runs (session_id, session_block_id, question_id, status, started_at, closes_at)
                     VALUES (?, ?, ?, 'OPEN', ?, ?)"
                )->execute([$sessionId, $blockId, $questionId, Time::toDb($now), $closes === null ? null : Time::toDb($closes)]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new ValidationException(['This question has already been run.']);
                }
                throw $e;
            }
            return ['closes_at' => $closes];
        });
        $this->broadcaster->broadcast($sessionId, [
            'type' => 'quiz_question_start',
            'session_block_id' => $blockId,
            'question_id' => $questionId,
            'server_time' => $now->format(DATE_ATOM),
            'closes_at' => $run['closes_at']?->format(DATE_ATOM),
        ]);
        return true;
    }

    /** Manual reveal by the tutor. */
    public function reveal(TenantDb $tenant, int $sessionId, int $blockId, string $questionId): bool
    {
        return $this->ownsSession($tenant, $sessionId) && $this->revealInternal($sessionId, $blockId, $questionId);
    }

    /** Reveals every OPEN run of the session whose deadline has passed. */
    public function expireDue(int $sessionId): void
    {
        $s = $this->pdo->prepare("SELECT session_block_id, question_id FROM quiz_question_runs WHERE session_id = ? AND status = 'OPEN' AND closes_at IS NOT NULL AND closes_at <= ?");
        $s->execute([$sessionId, Time::toDb($this->clock->now())]);
        foreach ($s->fetchAll() as $r) {
            $this->revealInternal($sessionId, (int) $r['session_block_id'], (string) $r['question_id']);
        }
    }

    private function revealInternal(int $sessionId, int $blockId, string $questionId): bool
    {
        $done = Transaction::run($this->pdo, function (PDO $pdo) use ($sessionId, $blockId, $questionId): bool {
            $s = $pdo->prepare('SELECT status FROM quiz_question_runs WHERE session_id = ? AND session_block_id = ? AND question_id = ? FOR UPDATE');
            $s->execute([$sessionId, $blockId, $questionId]);
            if ($s->fetchColumn() !== 'OPEN') {
                return false;
            }
            // closes submissions in the same transaction
            $pdo->prepare("UPDATE quiz_question_runs SET status = 'REVEALED', revealed_at = ? WHERE session_id = ? AND session_block_id = ? AND question_id = ?")
                ->execute([Time::toDb($this->clock->now()), $sessionId, $blockId, $questionId]);
            return true;
        });
        if ($done) {
            $q = self::question($this->config($sessionId, $blockId), $questionId);
            $this->broadcaster->broadcast($sessionId, [
                'type' => 'quiz_question_reveal',
                'session_block_id' => $blockId,
                'question_id' => $questionId,
                'correct_answer' => $q['correct_answer'] ?? null,
                'results' => $this->results($sessionId, $blockId, $questionId),
            ]);
        }
        return $done;
    }

    // ---------------------------------------------------------------- self-paced

    /**
     * Starts (idempotently) a participant's own timer for a question.
     *
     * @return array{started_at:string, deadline:?string}
     */
    public function openQuestion(ParticipantContext $p, int $blockId, string $questionId): array
    {
        return Transaction::run($this->pdo, function (PDO $pdo) use ($p, $blockId, $questionId): array {
            $config = $this->lockQuiz($pdo, $p->sessionId, $blockId, 'self', true);
            $q = self::question($config, $questionId) ?? throw new ValidationException(['Unknown question.']);
            $pdo->prepare(
                'INSERT IGNORE INTO quiz_participant_question_starts (session_id, session_block_id, participant_id, question_id, started_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$p->sessionId, $blockId, $p->participantId, $questionId, Time::toDb($this->clock->now())]);
            $started = $this->selfStart($pdo, $p, $blockId, $questionId);
            $deadline = self::deadline($q, $started);
            return ['started_at' => $started->format(DATE_ATOM), 'deadline' => $deadline?->format(DATE_ATOM)];
        });
    }

    // ---------------------------------------------------------------- answers

    /**
     * @param array<string,mixed> $payload {"answer": ...}
     * @throws ValidationException|BlockNotOpen|AnswerRejected
     */
    public function answer(ParticipantContext $p, int $blockId, string $questionId, array $payload): void
    {
        $this->expireDue($p->sessionId);
        $now = $this->clock->now();
        $result = Transaction::run($this->pdo, function (PDO $pdo) use ($p, $blockId, $questionId, $payload, $now): ?array {
            $config = $this->lockQuiz($pdo, $p->sessionId, $blockId, null, true);
            $q = self::question($config, $questionId) ?? throw new ValidationException(['Unknown question.']);
            if ($config['pacing'] === 'tutor') {
                $s = $pdo->prepare('SELECT status, closes_at FROM quiz_question_runs WHERE session_id = ? AND session_block_id = ? AND question_id = ? LOCK IN SHARE MODE');
                $s->execute([$p->sessionId, $blockId, $questionId]);
                $run = $s->fetch();
                if ($run === false || $run['status'] !== 'OPEN' || ($run['closes_at'] !== null && $now > Time::fromDb($run['closes_at']))) {
                    throw new AnswerRejected('This question is not open.');
                }
            } else {
                $s = $pdo->prepare('SELECT started_at FROM quiz_participant_question_starts WHERE session_id = ? AND session_block_id = ? AND participant_id = ? AND question_id = ? LOCK IN SHARE MODE');
                $s->execute([$p->sessionId, $blockId, $p->participantId, $questionId]);
                $started = $s->fetchColumn();
                if ($started === false) {
                    throw new AnswerRejected('Open the question first.');
                }
                $deadline = self::deadline($q, Time::fromDb((string) $started));
                if ($deadline !== null && $now > $deadline) {
                    throw new AnswerRejected('Time is up for this question.');
                }
            }
            $graded = QuizGrader::grade($q, $payload);
            try {
                $pdo->prepare(
                    'INSERT INTO quiz_answers (session_id, session_block_id, participant_id, moderation_actor_id, question_id, answer_payload, is_correct, points_awarded, submitted_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$p->sessionId, $blockId, $p->participantId, $p->moderationActorIdBinary(), $questionId,
                    json_encode(['answer' => $graded['answer']], JSON_THROW_ON_ERROR), $graded['correct'] ? 1 : 0, $graded['points'], Time::toDb($now)]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new AnswerRejected('You have already answered this question.');
                }
                throw $e;
            }
            $this->participants->recordActivity($p);
            return ['pacing' => $config['pacing']];
        });
        if ($result['pacing'] === 'tutor') {
            // live answer count for the tutor only (correctness stays hidden until reveal)
            $this->broadcaster->broadcast($p->sessionId, [
                'type' => 'activity_aggregate_update', 'session_block_id' => $blockId, 'question_id' => $questionId,
                'answers' => $this->answerCount($p->sessionId, $blockId, $questionId),
            ], Broadcaster::TARGET_TUTOR);
        }
    }

    // ---------------------------------------------------------------- views

    /**
     * Participant view of a quiz block. Never contains an answer key for a question that
     * is not revealed (tutor-paced) or before the participant finished (self-paced).
     *
     * @return array<string,mixed>
     */
    public function participantView(ParticipantContext $p, int $blockId): array
    {
        $this->expireDue($p->sessionId);
        $config = $this->config($p->sessionId, $blockId);
        $answers = $this->myAnswers($p, $blockId);
        $now = $this->clock->now();
        if ($config['pacing'] === 'tutor') {
            $runs = $this->runs($p->sessionId, $blockId);
            $questions = [];
            foreach ($config['questions'] as $q) {
                $run = $runs[$q['id']] ?? null;
                $status = $run['status'] ?? 'PENDING';
                $v = ['id' => $q['id'], 'status' => $status, 'answered' => isset($answers[$q['id']])];
                if ($status === 'OPEN') {
                    $v['closes_at'] = $run['closes_at'] === null ? null : Time::fromDb($run['closes_at'])->format(DATE_ATOM);
                }
                if ($status === 'REVEALED') {
                    $v['correct_answer'] = $q['correct_answer'];
                    $v['my_answer'] = $answers[$q['id']]['answer'] ?? null;
                    $v['my_correct'] = $answers[$q['id']]['correct'] ?? null;
                    $v['results'] = $this->results($p->sessionId, $blockId, $q['id']);
                }
                $questions[] = $v;
            }
            return ['pacing' => 'tutor', 'server_time' => $now->format(DATE_ATOM), 'questions' => $questions];
        }
        $starts = $this->selfStarts($p, $blockId);
        $finished = true;
        $progress = [];
        foreach ($config['questions'] as $q) {
            $started = $starts[$q['id']] ?? null;
            $deadline = $started === null ? null : self::deadline($q, $started);
            $answered = isset($answers[$q['id']]);
            $expired = $deadline !== null && $now > $deadline;
            $finished = $finished && ($answered || $expired);
            $progress[] = ['id' => $q['id'], 'started_at' => $started?->format(DATE_ATOM), 'deadline' => $deadline?->format(DATE_ATOM),
                'answered' => $answered, 'expired' => $expired && !$answered];
        }
        $out = ['pacing' => 'self', 'server_time' => $now->format(DATE_ATOM), 'progress' => $progress, 'finished' => $finished];
        if ($finished) {
            $out['review'] = array_map(static fn ($q) => [
                'id' => $q['id'],
                'correct_answer' => $q['correct_answer'],
                'my_answer' => $answers[$q['id']]['answer'] ?? null,
                'my_correct' => $answers[$q['id']]['correct'] ?? false,
                'points' => $answers[$q['id']]['points'] ?? 0,
            ], $config['questions']);
            $out['score'] = array_sum(array_column($out['review'], 'points'));
            $out['max_score'] = array_sum(array_column($config['questions'], 'points'));
        }
        return $out;
    }

    /**
     * Tutor view: per-question status, counts and distributions (tenant-scoped).
     *
     * @return array<string,mixed>|null
     */
    public function tutorView(TenantDb $tenant, int $sessionId, int $blockId): ?array
    {
        if (!$this->ownsSession($tenant, $sessionId)) {
            return null;
        }
        $this->expireDue($sessionId);
        $config = $this->config($sessionId, $blockId);
        $runs = $this->runs($sessionId, $blockId);
        $questions = [];
        foreach ($config['questions'] as $q) {
            $run = $runs[$q['id']] ?? null;
            $questions[] = [
                'id' => $q['id'],
                'status' => $config['pacing'] === 'tutor' ? ($run['status'] ?? 'PENDING') : null,
                'closes_at' => $run !== null && $run['closes_at'] !== null ? Time::fromDb($run['closes_at'])->format(DATE_ATOM) : null,
                'results' => $this->results($sessionId, $blockId, $q['id']),
            ];
        }
        return ['pacing' => $config['pacing'], 'server_time' => $this->clock->now()->format(DATE_ATOM), 'questions' => $questions];
    }

    /** @return array{answers:int, correct:int, distribution:array<string,int>} */
    private function results(int $sessionId, int $blockId, string $questionId): array
    {
        $s = $this->pdo->prepare('SELECT answer_payload, is_correct FROM quiz_answers WHERE session_id = ? AND session_block_id = ? AND question_id = ?');
        $s->execute([$sessionId, $blockId, $questionId]);
        $dist = [];
        $n = 0;
        $correct = 0;
        foreach ($s->fetchAll() as $r) {
            $n++;
            $correct += (int) $r['is_correct'];
            $a = json_decode((string) $r['answer_payload'], true, 16, JSON_THROW_ON_ERROR)['answer'];
            foreach (is_array($a) ? $a : [$a] as $v) {
                $k = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
                $dist[$k] = ($dist[$k] ?? 0) + 1;
            }
        }
        ksort($dist);
        return ['answers' => $n, 'correct' => $correct, 'distribution' => $dist];
    }

    private function answerCount(int $sessionId, int $blockId, string $questionId): int
    {
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM quiz_answers WHERE session_id = ? AND session_block_id = ? AND question_id = ?');
        $s->execute([$sessionId, $blockId, $questionId]);
        return (int) $s->fetchColumn();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Locks the session row (shared) and returns the quiz config if the block is the live,
     * current quiz block (optionally of a given pacing).
     *
     * @return array<string,mixed>
     */
    private function lockQuiz(PDO $pdo, int $sessionId, int $blockId, ?string $pacing, bool $requireCurrent): array
    {
        $s = $pdo->prepare(
            'SELECT s.status, s.current_session_block_id, b.block_type, b.config_snapshot
             FROM sessions s JOIN session_blocks b ON b.session_id = s.id WHERE s.id = ? AND b.id = ? LOCK IN SHARE MODE'
        );
        $s->execute([$sessionId, $blockId]);
        $row = $s->fetch();
        if ($row === false || $row['status'] !== 'live' || $row['block_type'] !== 'quiz'
            || ($requireCurrent && (int) $row['current_session_block_id'] !== $blockId)) {
            throw new BlockNotOpen();
        }
        $config = json_decode((string) $row['config_snapshot'], true, 64, JSON_THROW_ON_ERROR);
        if ($pacing !== null && $config['pacing'] !== $pacing) {
            throw new BlockNotOpen();
        }
        return $config;
    }

    /** @return array<string,mixed> */
    private function config(int $sessionId, int $blockId): array
    {
        $s = $this->pdo->prepare("SELECT config_snapshot FROM session_blocks WHERE id = ? AND session_id = ? AND block_type = 'quiz'");
        $s->execute([$blockId, $sessionId]);
        $v = $s->fetchColumn();
        if ($v === false) {
            throw new BlockNotOpen();
        }
        return json_decode((string) $v, true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,array<string,mixed>> */
    private function runs(int $sessionId, int $blockId): array
    {
        $s = $this->pdo->prepare('SELECT question_id, status, started_at, closes_at, revealed_at FROM quiz_question_runs WHERE session_id = ? AND session_block_id = ?');
        $s->execute([$sessionId, $blockId]);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $out[(string) $r['question_id']] = $r;
        }
        return $out;
    }

    /** @return array<string,array{answer:mixed,correct:bool,points:int}> */
    private function myAnswers(ParticipantContext $p, int $blockId): array
    {
        $s = $this->pdo->prepare('SELECT question_id, answer_payload, is_correct, points_awarded FROM quiz_answers WHERE session_id = ? AND session_block_id = ? AND participant_id = ?');
        $s->execute([$p->sessionId, $blockId, $p->participantId]);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $out[(string) $r['question_id']] = [
                'answer' => json_decode((string) $r['answer_payload'], true, 16, JSON_THROW_ON_ERROR)['answer'],
                'correct' => (bool) $r['is_correct'],
                'points' => (int) $r['points_awarded'],
            ];
        }
        return $out;
    }

    /** @return array<string,DateTimeImmutable> */
    private function selfStarts(ParticipantContext $p, int $blockId): array
    {
        $s = $this->pdo->prepare('SELECT question_id, started_at FROM quiz_participant_question_starts WHERE session_id = ? AND session_block_id = ? AND participant_id = ?');
        $s->execute([$p->sessionId, $blockId, $p->participantId]);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            $out[(string) $r['question_id']] = Time::fromDb((string) $r['started_at']);
        }
        return $out;
    }

    private function selfStart(PDO $pdo, ParticipantContext $p, int $blockId, string $questionId): DateTimeImmutable
    {
        $s = $pdo->prepare('SELECT started_at FROM quiz_participant_question_starts WHERE session_id = ? AND session_block_id = ? AND participant_id = ? AND question_id = ?');
        $s->execute([$p->sessionId, $blockId, $p->participantId, $questionId]);
        return Time::fromDb((string) $s->fetchColumn());
    }

    private static function deadline(array $q, DateTimeImmutable $start): ?DateTimeImmutable
    {
        return isset($q['time_limit_seconds']) ? $start->modify('+' . (int) $q['time_limit_seconds'] . ' seconds') : null;
    }

    /** @return array<string,mixed>|null */
    private static function question(array $config, string $id): ?array
    {
        foreach ($config['questions'] as $q) {
            if ($q['id'] === $id) {
                return $q;
            }
        }
        return null;
    }

    private function ownsSession(TenantDb $tenant, int $sessionId): bool
    {
        return $tenant->one('SELECT id FROM sessions WHERE id = :id AND tenant_id = :tenant_id', ['id' => $sessionId]) !== null;
    }
}
