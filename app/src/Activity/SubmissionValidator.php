<?php

declare(strict_types=1);

namespace Tutora\Activity;

use Tutora\Block\BlockType;
use Tutora\Support\Limits;
use Tutora\Support\Text;
use Tutora\Support\ValidationException;

/**
 * Validates a participant submission against the block's config snapshot and returns the
 * normalised payload that is stored (spec: Remaining Activity Types table).
 */
final class SubmissionValidator
{
    /**
     * @param array<string,mixed> $config config_snapshot of the session block
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public static function validate(BlockType $type, array $config, array $payload): array
    {
        $encoded = json_encode($payload);
        if ($encoded === false || strlen($encoded) > Limits::MAX_SUBMISSION_BYTES) {
            throw new ValidationException(['Submission is too large.']);
        }
        return match ($type) {
            BlockType::Poll => self::poll($config, $payload),
            BlockType::Meter => self::meter($config, $payload),
            BlockType::Rate => self::rate($config, $payload),
            BlockType::Rank => self::rank($config, $payload),
            BlockType::Word => self::word($config, $payload),
            BlockType::Plot => self::plot($config, $payload),
            BlockType::WordCloud => self::wordCloud($config, $payload),
            BlockType::Write => self::write($payload),
            default => throw new ValidationException(['This block does not accept submissions.']),
        };
    }

    /**
     * @return list<string>
     *
     * @param array<string,mixed> $config
     */
    private static function ids(array $config, string $key): array
    {
        return array_column($config[$key] ?? [], 'id');
    }

    /**
     * @return list<string>
     *
     * @param list<string> $allowed
     */
    private static function idList(mixed $v, array $allowed, int $min, int $max, string $what): array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) < $min || count($v) > $max) {
            throw new ValidationException([sprintf('Select between %d and %d %s.', $min, $max, $what)]);
        }
        foreach ($v as $id) {
            if (!is_string($id) || !in_array($id, $allowed, true)) {
                throw new ValidationException(['Unknown selection.']);
            }
        }
        if (count(array_unique($v)) !== count($v)) {
            throw new ValidationException(['Each option can only be selected once.']);
        }
        return $v;
    }

    /**
     * @param list<string> $keys
     * @param array<string,mixed> $payload
     */
    private static function onlyKeys(array $payload, array $keys): void
    {
        if (array_diff(array_keys($payload), $keys) !== []) {
            throw new ValidationException(['Unexpected fields in submission.']);
        }
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function poll(array $c, array $p): array
    {
        self::onlyKeys($p, ['selected']);
        $min = max(1, (int) ($c['min_selections'] ?? 1));
        return ['selected' => self::idList($p['selected'] ?? null, self::ids($c, 'options'), $min, (int) ($c['max_selections'] ?? 1), 'options')];
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function meter(array $c, array $p): array
    {
        self::onlyKeys($p, ['value']);
        $v = $p['value'] ?? null;
        if ((!is_int($v) && !is_float($v)) || !is_finite((float) $v) || $v < $c['min'] || $v > $c['max']) {
            throw new ValidationException(['Value is out of range.']);
        }
        $steps = ($v - $c['min']) / $c['step'];
        if (abs($steps - round($steps)) > 1e-6) {
            throw new ValidationException(['Value does not match the scale steps.']);
        }
        return ['value' => $v];
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function rate(array $c, array $p): array
    {
        self::onlyKeys($p, ['ratings']);
        $r = $p['ratings'] ?? null;
        $items = self::ids($c, 'items');
        if (!is_array($r) || $r === [] || array_is_list($r)) {
            throw new ValidationException(['Rate at least one item.']);
        }
        $out = [];
        foreach ($r as $id => $stars) {
            if (!in_array((string) $id, $items, true) || !is_int($stars) || $stars < 1 || $stars > (int) $c['scale']) {
                throw new ValidationException(['Invalid rating.']);
            }
            $out[(string) $id] = $stars;
        }
        return ['ratings' => $out];
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function rank(array $c, array $p): array
    {
        self::onlyKeys($p, ['order']);
        $items = self::ids($c, 'items');
        $order = self::idList($p['order'] ?? null, $items, count($items), count($items), 'items');
        return ['order' => $order];
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function word(array $c, array $p): array
    {
        self::onlyKeys($p, ['selected']);
        return ['selected' => self::idList($p['selected'] ?? null, self::ids($c, 'items'), 1, (int) $c['max_selections'], 'items')];
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function plot(array $c, array $p): array
    {
        self::onlyKeys($p, ['points']);
        $pts = $p['points'] ?? null;
        $items = self::ids($c, 'items');
        if (!is_array($pts) || !array_is_list($pts) || $pts === [] || count($pts) > count($items)) {
            throw new ValidationException(['Place at least one item.']);
        }
        $out = [];
        $seen = [];
        foreach ($pts as $pt) {
            if (!is_array($pt) || array_diff(array_keys($pt), ['item_id', 'x', 'y']) !== []) {
                throw new ValidationException(['Invalid point.']);
            }
            $id = $pt['item_id'] ?? null;
            $x = $pt['x'] ?? null;
            $y = $pt['y'] ?? null;
            if (!is_string($id) || !in_array($id, $items, true) || isset($seen[$id])
                || (!is_int($x) && !is_float($x)) || (!is_int($y) && !is_float($y))
                || !is_finite((float) $x) || !is_finite((float) $y)
                || $x < $c['x_axis']['min'] || $x > $c['x_axis']['max'] || $y < $c['y_axis']['min'] || $y > $c['y_axis']['max']) {
                throw new ValidationException(['Invalid point.']);
            }
            $seen[$id] = true;
            $out[] = ['item_id' => $id, 'x' => $x, 'y' => $y];
        }
        return ['points' => $out];
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function wordCloud(array $c, array $p): array
    {
        self::onlyKeys($p, ['words']);
        $words = $p['words'] ?? null;
        $max = (int) $c['max_words_per_participant'];
        if (!is_array($words) || !array_is_list($words) || $words === [] || count($words) > $max) {
            throw new ValidationException([sprintf('Enter between 1 and %d words.', $max)]);
        }
        $out = [];
        foreach ($words as $w) {
            $clean = Text::clean($w, Limits::WORD_CLOUD_WORD);
            if ($clean === null) {
                throw new ValidationException([sprintf('Each entry must be 1–%d characters.', Limits::WORD_CLOUD_WORD)]);
            }
            $out[mb_strtolower($clean, 'UTF-8')] ??= $clean;
        }
        return ['words' => array_values($out)];
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function write(array $p): array
    {
        self::onlyKeys($p, ['text']);
        $text = Text::clean($p['text'] ?? null, Limits::WRITE_RESPONSE, true);
        if ($text === null) {
            throw new ValidationException([sprintf('Response must be 1–%d characters.', Limits::WRITE_RESPONSE)]);
        }
        return ['text' => $text];
    }
}
