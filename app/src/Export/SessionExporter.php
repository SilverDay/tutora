<?php

declare(strict_types=1);

namespace Tutora\Export;

use Tutora\Tenant\TenantDb;

/**
 * Full session export as CSV (spec: the export unions the two answer shapes —
 * block_submissions for the generic activities and quiz_answers for Quiz — into one common
 * row format; Wall cards, a third shape, are included too).
 *
 * Participants appear only as per-session pseudonyms (P1, P2, … in join order), never with
 * display names: Write is anonymity-preserving, and pseudonyms cannot be linked across
 * sessions. Option/item ids are resolved to the labels from the session's config snapshot.
 * All queries are tenant-scoped.
 */
final class SessionExporter
{
    public const HEADER = ['block_position', 'block_type', 'block_prompt', 'participant', 'item', 'answer', 'is_correct', 'points', 'submitted_at_utc'];

    public function __construct(private readonly TenantDb $db)
    {
    }

    /** @return array{csv:string, rows:int}|null null if the session is not the tenant's */
    public function export(int $sessionId): ?array
    {
        if ($this->db->one('SELECT id FROM sessions WHERE id = :sid AND tenant_id = :tenant_id', ['sid' => $sessionId]) === null) {
            return null;
        }
        $blocks = [];
        foreach ($this->db->all(
            'SELECT b.id, b.position, b.block_type, b.config_snapshot FROM session_blocks b JOIN sessions s ON s.id = b.session_id
             WHERE s.id = :sid AND s.tenant_id = :tenant_id ORDER BY b.position',
            ['sid' => $sessionId],
        ) as $b) {
            $blocks[(int) $b['id']] = ['position' => (int) $b['position'] + 1, 'type' => (string) $b['block_type'],
                'config' => json_decode((string) $b['config_snapshot'], true, 64, JSON_THROW_ON_ERROR)];
        }
        $pseudonym = [];
        foreach ($this->db->all(
            'SELECT p.id FROM session_participants p JOIN sessions s ON s.id = p.session_id
             WHERE s.id = :sid AND s.tenant_id = :tenant_id ORDER BY p.id',
            ['sid' => $sessionId],
        ) as $i => $p) {
            $pseudonym[(int) $p['id']] = 'P' . ($i + 1);
        }
        $who = static fn (?int $pid): string => $pid === null ? 'Tutor' : ($pseudonym[$pid] ?? 'P?');

        $rows = [];
        foreach ($this->db->all(
            'SELECT bs.session_block_id, bs.participant_id, bs.payload, bs.updated_at FROM block_submissions bs
             JOIN sessions s ON s.id = bs.session_id WHERE s.id = :sid AND s.tenant_id = :tenant_id ORDER BY bs.id',
            ['sid' => $sessionId],
        ) as $r) {
            $b = $blocks[(int) $r['session_block_id']] ?? null;
            if ($b === null) {
                continue;
            }
            $payload = json_decode((string) $r['payload'], true, 64, JSON_THROW_ON_ERROR);
            foreach (self::submissionCells($b['type'], $b['config'], $payload) as [$item, $answer]) {
                $rows[] = [$b['position'], $b['type'], self::prompt($b['config']), $who((int) $r['participant_id']), $item, $answer, null, null, $r['updated_at']];
            }
        }
        foreach ($this->db->all(
            'SELECT qa.session_block_id, qa.participant_id, qa.question_id, qa.answer_payload, qa.is_correct, qa.points_awarded, qa.submitted_at
             FROM quiz_answers qa JOIN sessions s ON s.id = qa.session_id WHERE s.id = :sid AND s.tenant_id = :tenant_id ORDER BY qa.id',
            ['sid' => $sessionId],
        ) as $r) {
            $b = $blocks[(int) $r['session_block_id']] ?? null;
            if ($b === null) {
                continue;
            }
            $question = self::question($b['config'], (string) $r['question_id']);
            $answer = json_decode((string) $r['answer_payload'], true, 64, JSON_THROW_ON_ERROR)['answer'] ?? null;
            $rows[] = [$b['position'], 'quiz', (string) ($question['prompt'] ?? ''), $who((int) $r['participant_id']), (string) $r['question_id'],
                self::quizAnswer($question, $answer), (bool) $r['is_correct'], (int) $r['points_awarded'], $r['submitted_at']];
        }
        foreach ($this->db->all(
            'SELECT w.session_block_id, w.created_by, w.text, w.column_id, w.created_at FROM wall_cards w
             JOIN sessions s ON s.id = w.session_id WHERE s.id = :sid AND s.tenant_id = :tenant_id ORDER BY w.id',
            ['sid' => $sessionId],
        ) as $r) {
            $b = $blocks[(int) $r['session_block_id']] ?? null;
            if ($b === null) {
                continue;
            }
            $rows[] = [$b['position'], 'wall', self::prompt($b['config']), $who($r['created_by'] === null ? null : (int) $r['created_by']),
                self::label($b['config']['columns'] ?? [], (string) $r['column_id']), (string) $r['text'], null, null, $r['created_at']];
        }
        usort($rows, static fn (array $a, array $b) => [$a[0], $a[8]] <=> [$b[0], $b[8]]);

        $csv = new CsvWriter();
        $csv->row(self::HEADER);
        foreach ($rows as $row) {
            $csv->row($row);
        }
        return ['csv' => $csv->contents(), 'rows' => count($rows)];
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $p
     * @return array<int, array{0:?string, 1:mixed}>
     */
    private static function submissionCells(string $type, array $c, array $p): array
    {
        $labels = static fn (array $ids, string $key): string => implode('; ', array_map(static fn ($id) => self::label($c[$key] ?? [], (string) $id), $ids));
        return match ($type) {
            'poll' => [[null, $labels($p['selected'] ?? [], 'options')]],
            'word' => [[null, $labels($p['selected'] ?? [], 'items')]],
            'meter' => [[null, $p['value'] ?? '']],
            'rate' => array_map(static fn ($id, $stars) => [self::label($c['items'] ?? [], (string) $id), (int) $stars], array_keys($p['ratings'] ?? []), array_values($p['ratings'] ?? [])),
            'rank' => [[null, implode(' > ', array_map(static fn ($id) => self::label($c['items'] ?? [], (string) $id), $p['order'] ?? []))]],
            'plot' => array_map(static fn ($pt) => [self::label($c['items'] ?? [], (string) $pt['item_id']), 'x=' . $pt['x'] . '; y=' . $pt['y']], $p['points'] ?? []),
            'word_cloud' => [[null, implode('; ', array_map('strval', $p['words'] ?? []))]],
            'write' => [[null, (string) ($p['text'] ?? '')]],
            default => [[null, json_encode($p, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
        };
    }

    /** @param array<string,mixed> $question */
    private static function quizAnswer(array $question, mixed $answer): string
    {
        return match (true) {
            is_bool($answer) => $answer ? 'true' : 'false',
            is_array($answer) => implode('; ', array_map(static fn ($id) => self::label($question['options'] ?? [], (string) $id), $answer)),
            is_string($answer) && ($question['type'] ?? '') === 'single' => self::label($question['options'] ?? [], $answer),
            $answer === null => '',
            default => (string) $answer,
        };
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function question(array $config, string $id): array
    {
        foreach ($config['questions'] ?? [] as $q) {
            if (($q['id'] ?? null) === $id) {
                return $q;
            }
        }
        return [];
    }

    /** @param array<string,mixed> $config */
    private static function prompt(array $config): string
    {
        return (string) ($config['question'] ?? $config['prompt'] ?? '');
    }

    /** @param list<array{id:string,label:string}>|mixed $list */
    private static function label(mixed $list, string $id): string
    {
        foreach (is_array($list) ? $list : [] as $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $id) {
                return (string) ($entry['label'] ?? $id);
            }
        }
        return $id;
    }
}
