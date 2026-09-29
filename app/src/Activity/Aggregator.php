<?php

declare(strict_types=1);

namespace Tutora\Activity;

use Tutora\Block\BlockType;

/**
 * Computes the aggregate for a block from its (validated) submission payloads.
 * Aggregates are anonymous: no participant or actor ids are included.
 */
final class Aggregator
{
    /**
     * @param array<string,mixed> $config
     * @param list<array<string,mixed>> $payloads
     * @return array<string,mixed>
     */
    public static function aggregate(BlockType $type, array $config, array $payloads): array
    {
        $n = count($payloads);
        $out = match ($type) {
            BlockType::Poll => self::tally($config['options'] ?? [], $payloads, 'selected', $n),
            BlockType::Word => self::tally($config['items'] ?? [], $payloads, 'selected', $n),
            BlockType::Meter => self::meter($config, array_map(static fn ($p) => $p['value'], $payloads)),
            BlockType::Rate => self::rate($config, $payloads),
            BlockType::Rank => self::borda($config['items'] ?? [], $payloads),
            BlockType::Plot => self::plot($config['items'] ?? [], $payloads),
            BlockType::WordCloud => self::cloud($payloads),
            // Write: raw responses are tutor-only; the shared aggregate is the count
            BlockType::Write => [],
            default => [],
        };
        return ['responses' => $n] + $out;
    }

    /** Count + percentage (of respondents) per option. */
    private static function tally(array $options, array $payloads, string $key, int $n): array
    {
        $counts = array_fill_keys(array_column($options, 'id'), 0);
        foreach ($payloads as $p) {
            foreach ($p[$key] ?? [] as $id) {
                if (isset($counts[$id])) {
                    $counts[$id]++;
                }
            }
        }
        $rows = [];
        foreach ($options as $o) {
            $c = $counts[$o['id']];
            $rows[] = ['id' => $o['id'], 'label' => $o['label'], 'count' => $c, 'percent' => $n === 0 ? 0.0 : round(100 * $c / $n, 1)];
        }
        return ['options' => $rows];
    }

    /** @param list<int|float> $values */
    private static function meter(array $config, array $values): array
    {
        if ($values === []) {
            return ['mean' => null, 'median' => null, 'distribution' => []];
        }
        sort($values);
        $n = count($values);
        $median = $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
        // one bin per scale step for small scales, otherwise 20 equal-width bins
        $min = $config['min'];
        $steps = (int) round(($config['max'] - $min) / $config['step']);
        $perStep = $steps + 1 <= 20;
        $bins = $perStep ? $steps + 1 : 20;
        $width = $perStep ? $config['step'] : ($config['max'] - $min) / 20;
        $dist = [];
        for ($i = 0; $i < $bins; $i++) {
            $dist[$i] = ['from' => $min + $i * $width, 'count' => 0];
        }
        foreach ($values as $v) {
            $i = $perStep ? (int) round(($v - $min) / $width) : (int) floor(($v - $min) / $width);
            $dist[max(0, min($bins - 1, $i))]['count']++;
        }
        return ['mean' => round(array_sum($values) / $n, 3), 'median' => $median, 'distribution' => array_values($dist)];
    }

    private static function rate(array $config, array $payloads): array
    {
        $scale = (int) $config['scale'];
        $rows = [];
        foreach ($config['items'] as $item) {
            $dist = array_fill(1, $scale, 0);
            $sum = 0;
            $cnt = 0;
            foreach ($payloads as $p) {
                $s = $p['ratings'][$item['id']] ?? null;
                if (is_int($s) && isset($dist[$s])) {
                    $dist[$s]++;
                    $sum += $s;
                    $cnt++;
                }
            }
            $rows[] = ['id' => $item['id'], 'label' => $item['label'], 'ratings' => $cnt,
                'average' => $cnt === 0 ? null : round($sum / $cnt, 2), 'distribution' => array_values($dist)];
        }
        return ['items' => $rows];
    }

    /** Borda count: with n items, position i (0-based) earns n-1-i points. */
    private static function borda(array $items, array $payloads): array
    {
        $n = count($items);
        $points = array_fill_keys(array_column($items, 'id'), 0);
        foreach ($payloads as $p) {
            foreach (array_values($p['order'] ?? []) as $i => $id) {
                if (isset($points[$id])) {
                    $points[$id] += $n - 1 - $i;
                }
            }
        }
        $rows = [];
        foreach ($items as $item) {
            $rows[] = ['id' => $item['id'], 'label' => $item['label'], 'points' => $points[$item['id']]];
        }
        usort($rows, static fn ($a, $b) => $b['points'] <=> $a['points'] ?: strcmp($a['id'], $b['id']));
        return ['ranking' => $rows];
    }

    /** Per-item scatter cluster (anonymous points) and centroid. */
    private static function plot(array $items, array $payloads): array
    {
        $rows = [];
        foreach ($items as $item) {
            $pts = [];
            foreach ($payloads as $p) {
                foreach ($p['points'] ?? [] as $pt) {
                    if ($pt['item_id'] === $item['id']) {
                        $pts[] = ['x' => $pt['x'], 'y' => $pt['y']];
                    }
                }
            }
            $c = count($pts);
            $rows[] = ['id' => $item['id'], 'label' => $item['label'], 'points' => $pts,
                'centroid' => $c === 0 ? null : ['x' => round(array_sum(array_column($pts, 'x')) / $c, 3), 'y' => round(array_sum(array_column($pts, 'y')) / $c, 3)]];
        }
        return ['items' => $rows];
    }

    /** Frequency tally, case-insensitive, top 100. */
    private static function cloud(array $payloads): array
    {
        $freq = [];
        $display = [];
        foreach ($payloads as $p) {
            foreach ($p['words'] ?? [] as $w) {
                $k = mb_strtolower($w, 'UTF-8');
                $freq[$k] = ($freq[$k] ?? 0) + 1;
                $display[$k] ??= $w;
            }
        }
        arsort($freq);
        $rows = [];
        foreach (array_slice($freq, 0, 100, true) as $k => $c) {
            $rows[] = ['word' => $display[$k], 'count' => $c];
        }
        return ['words' => $rows];
    }
}
