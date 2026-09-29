<?php

declare(strict_types=1);

namespace Tutora\Ai;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tutora\Activity\SubmissionService;
use Tutora\Audit\AuditLog;
use Tutora\Database\Transaction;
use Tutora\Realtime\Broadcaster;
use Tutora\Security\Logger;
use Tutora\Support\Clock;
use Tutora\Support\Time;
use Tutora\Tenant\TenantDb;

/**
 * Tutor-triggered AI summaries of Write responses (spec: AI (Write) Integration).
 *
 * Two independent limits, deliberately not merged (spec): the tenant's monthly quota in
 * ai_quota (calls and tokens) and a hard per-session, per-day call cap counted from
 * ai_usage. Both are checked and a call is reserved under the tenant's ai_quota row lock
 * before the provider is contacted, so concurrent requests cannot overshoot; a failed
 * provider call still counts (abuse protection). Only counts reach the audit log and the
 * application log, never prompts, responses or summaries.
 */
final class SummaryService
{
    public const MAX_SUMMARY_CHARS = 4000;

    /**
     * Owner decision (2026-09-29): a summary can be shared with the group only if it is based
     * on at least this many responses; with fewer, it would effectively reveal individual answers.
     */
    public const MIN_RESPONSES_TO_SHARE = 3;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly ?SummaryProvider $provider,
        private readonly SubmissionService $submissions,
        private readonly Broadcaster $broadcaster,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        private readonly int $quotaCallsPerMonth = 200,
        private readonly int $quotaTokensPerMonth = 500_000,
        private readonly int $callsPerSessionPerDay = 10,
        private readonly int $maxInputChars = 60_000,
    ) {
    }

    public function enabled(): bool
    {
        return $this->provider !== null;
    }

    /**
     * @throws AiUnavailable 'disabled' | 'provider_failed'
     * @throws AiLimitReached 'quota' | 'session_cap'
     * @return bool false if the block is not a Write block of the tenant's session or has no responses
     */
    public function generate(TenantDb $tenant, int $sessionId, int $blockId, ?string $ip): bool
    {
        if ($this->provider === null) {
            throw new AiUnavailable('disabled');
        }
        $block = $tenant->one(
            "SELECT b.config_snapshot FROM session_blocks b JOIN sessions s ON s.id = b.session_id
             WHERE b.id = :bid AND s.id = :sid AND s.tenant_id = :tenant_id AND b.block_type = 'write'",
            ['bid' => $blockId, 'sid' => $sessionId],
        );
        if ($block === null) {
            return false;
        }
        $all = array_column($this->submissions->writeResponses($tenant, $sessionId, $blockId), 'text');
        if ($all === []) {
            return false;
        }
        $used = [];
        $chars = 0;
        foreach ($all as $text) {
            $chars += mb_strlen($text, 'UTF-8');
            if ($chars > $this->maxInputChars) {
                break;
            }
            $used[] = $text;
        }
        $question = (string) (json_decode((string) $block['config_snapshot'], true, 64, JSON_THROW_ON_ERROR)['prompt'] ?? '');

        $tenantId = $tenant->tenantId();
        $usageId = $this->reserve($tenantId, $sessionId, $blockId);
        try {
            $result = $this->provider->generateSummary(SummaryPrompt::build($question, $used));
        } catch (\Throwable $e) {
            $this->logger->error('AI summary provider failed', ['error' => $e::class, 'session_id' => $sessionId]);
            throw new AiUnavailable('provider_failed');
        }
        $tokensIn = max(0, $result->tokensIn);
        $tokensOut = max(0, $result->tokensOut);
        $text = self::sanitize($result->text);
        $now = Time::toDb($this->clock->now());
        Transaction::run($this->pdo, function (PDO $pdo) use ($usageId, $tenantId, $tokensIn, $tokensOut, $sessionId, $blockId, $text, $all, $used, $now): void {
            $pdo->prepare('UPDATE ai_usage SET tokens_in = ?, tokens_out = ? WHERE id = ?')->execute([$tokensIn, $tokensOut, $usageId]);
            $pdo->prepare('UPDATE ai_quota SET tokens_used = tokens_used + ? WHERE tenant_id = ? AND period_start = ?')
                ->execute([$tokensIn + $tokensOut, $tenantId, $this->periodStart()]);
            // a new summary is not shared until the tutor shares it again
            $pdo->prepare(
                'INSERT INTO write_summaries (session_block_id, session_id, summary_text, responses_total, responses_used, generated_at, shared_at)
                 VALUES (?, ?, ?, ?, ?, ?, NULL)
                 ON DUPLICATE KEY UPDATE summary_text = VALUES(summary_text), responses_total = VALUES(responses_total),
                    responses_used = VALUES(responses_used), generated_at = VALUES(generated_at), shared_at = NULL'
            )->execute([$blockId, $sessionId, $text, count($all), count($used), $now]);
        });
        $this->audit->record($tenantId, AuditLog::AI_SUMMARY, $ip, [
            'session_id' => $sessionId, 'block_id' => $blockId, 'responses' => count($all),
            'responses_used' => count($used), 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut,
        ]);
        $this->broadcaster->broadcast($sessionId, ['type' => 'write_summary_shared', 'session_block_id' => $blockId]);
        return true;
    }

    /**
     * Shares the current summary with the participants (explicit tutor action).
     *
     * @throws SummaryNotShareable if it is based on fewer than MIN_RESPONSES_TO_SHARE responses
     */
    public function share(TenantDb $tenant, int $sessionId, int $blockId): bool
    {
        // tenant-scoped existence check first (an UPDATE that changes nothing reports 0 rows)
        $row = $tenant->one(
            'SELECT ws.responses_used FROM write_summaries ws JOIN sessions s ON s.id = ws.session_id
             WHERE ws.session_block_id = :bid AND s.id = :sid AND s.tenant_id = :tenant_id',
            ['bid' => $blockId, 'sid' => $sessionId],
        );
        if ($row === null) {
            return false;
        }
        if ((int) $row['responses_used'] < self::MIN_RESPONSES_TO_SHARE) {
            throw new SummaryNotShareable('too_few_responses');
        }
        $tenant->run(
            'UPDATE write_summaries ws JOIN sessions s ON s.id = ws.session_id
             SET ws.shared_at = COALESCE(ws.shared_at, :now)
             WHERE ws.session_block_id = :bid AND s.id = :sid AND s.tenant_id = :tenant_id',
            ['now' => Time::toDb($this->clock->now()), 'bid' => $blockId, 'sid' => $sessionId],
        );
        $this->broadcaster->broadcast($sessionId, ['type' => 'write_summary_shared', 'session_block_id' => $blockId]);
        return true;
    }

    /** @return array{enabled:bool, summary:?string, responses_total:?int, responses_used:?int, generated_at:?string, shared:bool, shareable:bool, min_to_share:int} */
    public function forTutor(int $sessionId, int $blockId): array
    {
        $row = $this->summaryRow($sessionId, $blockId);
        return [
            'enabled' => $this->enabled(),
            'summary' => $row === null ? null : (string) $row['summary_text'],
            'responses_total' => $row === null ? null : (int) $row['responses_total'],
            'responses_used' => $row === null ? null : (int) $row['responses_used'],
            'generated_at' => $row === null ? null : (string) $row['generated_at'],
            'shared' => $row !== null && $row['shared_at'] !== null,
            'shareable' => $row !== null && (int) $row['responses_used'] >= self::MIN_RESPONSES_TO_SHARE,
            'min_to_share' => self::MIN_RESPONSES_TO_SHARE,
        ];
    }

    /** Only a summary the tutor shared, never the raw responses. */
    public function forParticipant(int $sessionId, int $blockId): ?string
    {
        $row = $this->summaryRow($sessionId, $blockId);
        return $row !== null && $row['shared_at'] !== null ? (string) $row['summary_text'] : null;
    }

    /** Display-only plain text: no control/bidi characters, bounded length. */
    public static function sanitize(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', str_replace("\r\n", "\n", $text));
        $text = trim($text);
        return mb_strlen($text, 'UTF-8') > self::MAX_SUMMARY_CHARS ? mb_substr($text, 0, self::MAX_SUMMARY_CHARS, 'UTF-8') . '…' : $text;
    }

    /** Checks both limits and reserves one call, atomically per tenant. Returns the ai_usage id. */
    private function reserve(int $tenantId, int $sessionId, int $blockId): int
    {
        return Transaction::run($this->pdo, function (PDO $pdo) use ($tenantId, $sessionId, $blockId): int {
            $start = $this->periodStart();
            $end = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify('+1 month')->format('Y-m-d H:i:s.v');
            $pdo->prepare('INSERT IGNORE INTO ai_quota (tenant_id, period_start, period_end, tokens_used, calls_used) VALUES (?, ?, ?, 0, 0)')
                ->execute([$tenantId, $start, $end]);
            $q = $pdo->prepare('SELECT calls_used, tokens_used FROM ai_quota WHERE tenant_id = ? AND period_start = ? FOR UPDATE');
            $q->execute([$tenantId, $start]);
            $quota = $q->fetch();
            if ((int) $quota['calls_used'] >= $this->quotaCallsPerMonth || (int) $quota['tokens_used'] >= $this->quotaTokensPerMonth) {
                throw new AiLimitReached('quota');
            }
            $dayStart = $this->utcNow()->setTime(0, 0); // per UTC day
            $c = $pdo->prepare('SELECT COUNT(*) FROM ai_usage WHERE session_id = ? AND created_at >= ?');
            $c->execute([$sessionId, Time::toDb($dayStart)]);
            if ((int) $c->fetchColumn() >= $this->callsPerSessionPerDay) {
                throw new AiLimitReached('session_cap');
            }
            $pdo->prepare('UPDATE ai_quota SET calls_used = calls_used + 1 WHERE tenant_id = ? AND period_start = ?')->execute([$tenantId, $start]);
            $pdo->prepare('INSERT INTO ai_usage (tenant_id, session_id, block_id, tokens_in, tokens_out, created_at) VALUES (?, ?, ?, 0, 0, ?)')
                ->execute([$tenantId, $sessionId, $blockId, Time::toDb($this->clock->now())]);
            return (int) $pdo->lastInsertId();
        });
    }

    /** Quota period: the calendar month in UTC. */
    private function periodStart(): string
    {
        return $this->utcNow()->modify('first day of this month')->setTime(0, 0)->format('Y-m-d H:i:s.v');
    }

    private function utcNow(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return array<string,mixed>|null */
    private function summaryRow(int $sessionId, int $blockId): ?array
    {
        $s = $this->pdo->prepare('SELECT summary_text, responses_total, responses_used, generated_at, shared_at FROM write_summaries WHERE session_block_id = ? AND session_id = ?');
        $s->execute([$blockId, $sessionId]);
        $row = $s->fetch();
        return $row === false ? null : $row;
    }
}
