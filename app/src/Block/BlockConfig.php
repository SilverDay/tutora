<?php

declare(strict_types=1);

namespace Tutora\Block;

use Tutora\Support\Limits;
use Tutora\Support\ValidationException;

/**
 * Per-type config validation (config_version 1) and role-aware projection.
 *
 * A slide/whiteboard/annotate base image is not part of the config: it is the
 * block's slide_asset_id column, validated against the tenant's own imports.
 */
final class BlockConfig
{
    public const CURRENT_VERSION = 1;

    /** Config keys that must never reach participant clients (spec: Quiz rule 1). */
    private const QUIZ_SECRET_KEYS = ['correct_answer', 'tolerance'];

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed> normalised config
     * @throws ValidationException
     */
    public static function validate(BlockType $type, array $config): array
    {
        $encoded = json_encode($config);
        if ($encoded === false || strlen($encoded) > Limits::MAX_CONFIG_BYTES) {
            throw new ValidationException(['Block configuration is too large.']);
        }
        $r = new ConfigReader($config);
        $out = match ($type) {
            BlockType::Slide => [],
            BlockType::Whiteboard => ['mode' => $r->enum('mode', ['presenter', 'collaborative'], 'presenter')],
            BlockType::Annotate => [
                'prompt' => $r->string('prompt', Limits::PROMPT),
                'tags' => $r->items('tags', 1, Limits::MAX_OPTIONS, 't'),
            ],
            BlockType::Quiz => self::quiz($r),
            BlockType::Poll => self::poll($r),
            BlockType::Meter => self::meter($r),
            BlockType::Rate => [
                'items' => $r->items('items', 1, Limits::MAX_ITEMS, 'i'),
                'scale' => $r->int('scale', 2, 10, 5),
            ],
            BlockType::Rank => ['items' => $r->items('items', 2, Limits::MAX_ITEMS, 'i')],
            BlockType::Word => self::word($r),
            BlockType::Plot => [
                'x_axis' => self::axis($r, 'x_axis'),
                'y_axis' => self::axis($r, 'y_axis'),
                'items' => $r->items('items', 1, Limits::MAX_ITEMS, 'i'),
            ],
            BlockType::WordCloud => [
                'prompt' => $r->string('prompt', Limits::PROMPT),
                'max_words_per_participant' => $r->int('max_words_per_participant', 1, 10, 3),
            ],
            BlockType::Write => ['prompt' => $r->string('prompt', Limits::PROMPT)],
            BlockType::Wall => [
                'prompt' => $r->string('prompt', Limits::PROMPT, false),
                'columns' => $r->items('columns', 1, 10, 'c'),
            ],
        };
        $r->finish();
        $r->throwIfInvalid();
        return array_filter($out, static fn ($v) => $v !== null);
    }

    /**
     * What a participant is allowed to see of a block's config.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function participantView(BlockType $type, array $config): array
    {
        if ($type !== BlockType::Quiz) {
            return $config;
        }
        $config['questions'] = array_map(
            static fn (array $q) => array_diff_key($q, array_flip(self::QUIZ_SECRET_KEYS)),
            $config['questions'] ?? [],
        );
        return $config;
    }

    /** @return array<string,mixed> */
    private static function poll(ConfigReader $r): array
    {
        $options = $r->items('options', 2, Limits::MAX_OPTIONS, 'o');
        $min = $r->int('min_selections', 0, Limits::MAX_OPTIONS, 1);
        $max = $r->int('max_selections', 1, Limits::MAX_OPTIONS, 1);
        if ($min !== null && $max !== null && ($min > $max || $max > max(1, count($options)))) {
            $r->error('config: selection limits must satisfy min ≤ max ≤ number of options.');
        }
        return ['question' => $r->string('question', Limits::PROMPT), 'options' => $options, 'min_selections' => $min, 'max_selections' => $max];
    }

    /** @return array<string,mixed> */
    private static function meter(ConfigReader $r): array
    {
        $min = $r->number('min', -1e9, 1e9);
        $max = $r->number('max', -1e9, 1e9);
        $step = $r->number('step', 1e-6, 1e9);
        if ($min !== null && $max !== null && $step !== null) {
            if ($min >= $max) {
                $r->error('config.min must be less than config.max.');
            } elseif (($max - $min) / $step > 10000) {
                $r->error('config.step is too small for the range (max 10000 steps).');
            }
        }
        $labels = $r->object('labels', false);
        $outLabels = null;
        if ($labels !== null) {
            $lr = new ConfigReader($labels, 'config.labels');
            $outLabels = array_filter(['min' => $lr->string('min', Limits::LABEL, false), 'max' => $lr->string('max', Limits::LABEL, false)], static fn ($v) => $v !== null);
            $lr->finish();
            $r->mergeErrors($lr->errors());
        }
        return ['prompt' => $r->string('prompt', Limits::PROMPT), 'min' => $min, 'max' => $max, 'step' => $step, 'labels' => $outLabels];
    }

    /** @return array<string,mixed> */
    private static function word(ConfigReader $r): array
    {
        $items = $r->items('items', 2, Limits::MAX_ITEMS, 'i');
        $max = $r->int('max_selections', 1, Limits::MAX_ITEMS, 1);
        if ($max !== null && $items !== [] && $max > count($items)) {
            $r->error('config.max_selections cannot exceed the number of items.');
        }
        return ['items' => $items, 'max_selections' => $max];
    }

    /** @return array<string,mixed>|null */
    private static function axis(ConfigReader $r, string $key): ?array
    {
        $o = $r->object($key);
        if ($o === null) {
            return null;
        }
        $ar = new ConfigReader($o, "config.{$key}");
        $axis = ['label' => $ar->string('label', Limits::LABEL), 'min' => $ar->number('min', -1e9, 1e9), 'max' => $ar->number('max', -1e9, 1e9)];
        if ($axis['min'] !== null && $axis['max'] !== null && $axis['min'] >= $axis['max']) {
            $ar->error("config.{$key}.min must be less than max.");
        }
        $ar->finish();
        $r->mergeErrors($ar->errors());
        return $axis;
    }

    /** @return array<string,mixed> */
    private static function quiz(ConfigReader $r): array
    {
        $pacing = $r->enum('pacing', ['tutor', 'self'], 'tutor');
        $questions = [];
        $ids = [];
        foreach ($r->objects('questions', 1, Limits::MAX_QUIZ_QUESTIONS) as $i => $q) {
            $ctx = "config.questions[{$i}]";
            $qr = new ConfigReader($q, $ctx);
            $id = $q['id'] ?? ('q' . ($i + 1));
            $qr->raw('id');
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $id) !== 1 || isset($ids[$id])) {
                $qr->error("{$ctx}.id must be unique (letters, digits, _ or -).");
            }
            $ids[(string) (is_string($id) ? $id : '')] = true;
            $type = $qr->enum('type', ['single', 'multi', 'true_false', 'numeric']);
            $out = [
                'id' => $id,
                'type' => $type,
                'prompt' => $qr->string('prompt', Limits::PROMPT),
                'points' => $qr->int('points', 0, 1000, 1),
            ];
            if ($qr->has('time_limit_seconds') && $qr->raw('time_limit_seconds') !== null) {
                $out['time_limit_seconds'] = $qr->int('time_limit_seconds', 5, 3600);
            } else {
                $qr->raw('time_limit_seconds');
            }
            $answer = $qr->raw('correct_answer');
            switch ($type) {
                case 'single':
                case 'multi':
                    $options = $qr->items('options', 2, Limits::MAX_OPTIONS, 'o');
                    $out['options'] = $options;
                    $optionIds = array_column($options, 'id');
                    if ($type === 'single') {
                        if (!is_string($answer) || !in_array($answer, $optionIds, true)) {
                            $qr->error("{$ctx}.correct_answer must be one option id.");
                        }
                    } elseif (!is_array($answer) || !array_is_list($answer) || $answer === []
                        || array_diff($answer, $optionIds) !== [] || count(array_unique($answer)) !== count($answer)) {
                        $qr->error("{$ctx}.correct_answer must be a non-empty list of distinct option ids.");
                    } else {
                        sort($answer);
                    }
                    break;
                case 'true_false':
                    if (!is_bool($answer)) {
                        $qr->error("{$ctx}.correct_answer must be true or false.");
                    }
                    break;
                case 'numeric':
                    if ((!is_int($answer) && !is_float($answer)) || !is_finite((float) $answer)) {
                        $qr->error("{$ctx}.correct_answer must be a number.");
                    }
                    $out['tolerance'] = $qr->number('tolerance', 0, 1e9, false) ?? 0;
                    break;
            }
            $out['correct_answer'] = $answer;
            $qr->finish();
            $r->mergeErrors($qr->errors());
            $questions[] = $out;
        }
        return ['pacing' => $pacing, 'questions' => $questions];
    }
}
