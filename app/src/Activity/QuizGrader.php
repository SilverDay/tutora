<?php

declare(strict_types=1);

namespace Tutora\Activity;

use Tutora\Support\ValidationException;

/** Validates and auto-grades an answer (v1: single, multi, true/false, numeric). */
final class QuizGrader
{
    /**
     * @param array<string,mixed> $question from the config snapshot (with answer key)
     * @param array<string,mixed> $payload {"answer": ...}
     * @return array{answer: mixed, correct: bool, points: int}
     * @throws ValidationException
     */
    public static function grade(array $question, array $payload): array
    {
        if (array_keys($payload) !== ['answer']) {
            throw new ValidationException(['Expected an answer.']);
        }
        $a = $payload['answer'];
        $key = $question['correct_answer'];
        switch ($question['type']) {
            case 'single':
                if (!is_string($a) || !in_array($a, array_column($question['options'], 'id'), true)) {
                    throw new ValidationException(['Choose one option.']);
                }
                $correct = $a === $key;
                break;
            case 'multi':
                $ids = array_column($question['options'], 'id');
                if (!is_array($a) || !array_is_list($a) || $a === [] || count(array_unique($a)) !== count($a)) {
                    throw new ValidationException(['Choose one or more options.']);
                }
                foreach ($a as $id) {
                    if (!is_string($id) || !in_array($id, $ids, true)) {
                        throw new ValidationException(['Unknown option.']);
                    }
                }
                sort($a);
                $correct = $a === $key;
                break;
            case 'true_false':
                if (!is_bool($a)) {
                    throw new ValidationException(['Answer true or false.']);
                }
                $correct = $a === $key;
                break;
            case 'numeric':
                if ((!is_int($a) && !is_float($a)) || !is_finite((float) $a)) {
                    throw new ValidationException(['Enter a number.']);
                }
                $correct = abs($a - $key) <= ($question['tolerance'] ?? 0) + 1e-9;
                break;
            default:
                throw new ValidationException(['Unsupported question type.']);
        }
        return ['answer' => $a, 'correct' => $correct, 'points' => $correct ? (int) $question['points'] : 0];
    }
}
