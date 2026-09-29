<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Block\BlockConfig;
use Tutora\Block\BlockType;
use Tutora\Support\ValidationException;

final class BlockConfigTest extends TestCase
{
    private static function errors(BlockType $t, array $c): array
    {
        try {
            BlockConfig::validate($t, $c);
            return [];
        } catch (ValidationException $e) {
            return $e->errors;
        }
    }

    public function testPollNormalisesAndAssignsIds(): void
    {
        $c = BlockConfig::validate(BlockType::Poll, ['question' => ' Best? ', 'options' => ['A', ['id' => 'b', 'label' => 'B']]]);
        self::assertSame(['question' => 'Best?', 'options' => [['id' => 'o1', 'label' => 'A'], ['id' => 'b', 'label' => 'B']], 'min_selections' => 1, 'max_selections' => 1, 'results' => 'live'], $c);
    }

    public function testResultsVisibilitySetting(): void
    {
        self::assertSame('on_reveal', BlockConfig::validate(BlockType::Poll, ['question' => 'q', 'options' => ['a', 'b'], 'results' => 'on_reveal'])['results']);
        self::assertSame('live', BlockConfig::validate(BlockType::Write, ['prompt' => 'p'])['results'], 'default live');
        self::assertNotEmpty(self::errors(BlockType::Poll, ['question' => 'q', 'options' => ['a', 'b'], 'results' => 'never']));
        // not for Wall (shared content), Quiz (own reveal) or display-only blocks
        self::assertNotEmpty(self::errors(BlockType::Wall, ['columns' => ['A'], 'results' => 'on_reveal']));
        self::assertNotEmpty(self::errors(BlockType::Quiz, ['pacing' => 'tutor', 'questions' => [], 'results' => 'on_reveal']));
        self::assertNotEmpty(self::errors(BlockType::Slide, ['results' => 'on_reveal']));
        self::assertFalse(BlockConfig::resultsOnReveal(BlockType::Poll, ['question' => 'q']), 'configs stored before the key existed are live');
        self::assertTrue(BlockConfig::resultsOnReveal(BlockType::Meter, ['results' => 'on_reveal']));
        self::assertFalse(BlockConfig::resultsOnReveal(BlockType::Wall, ['results' => 'on_reveal']));
    }

    public function testUnknownKeysRejected(): void
    {
        self::assertNotEmpty(self::errors(BlockType::Write, ['prompt' => 'x', 'html' => '<b>']));
        self::assertNotEmpty(self::errors(BlockType::Poll, ['question' => 'q', 'options' => [['label' => 'a', 'onclick' => 'x'], 'b']]));
    }

    public function testPollSelectionBounds(): void
    {
        self::assertNotEmpty(self::errors(BlockType::Poll, ['question' => 'q', 'options' => ['a', 'b'], 'max_selections' => 3]));
        self::assertNotEmpty(self::errors(BlockType::Poll, ['question' => 'q', 'options' => ['a', 'b'], 'min_selections' => 2, 'max_selections' => 1]));
        self::assertNotEmpty(self::errors(BlockType::Poll, ['question' => 'q', 'options' => ['a']]));
    }

    public function testDuplicateIdsRejected(): void
    {
        self::assertNotEmpty(self::errors(BlockType::Rank, ['items' => [['id' => 'x', 'label' => 'a'], ['id' => 'x', 'label' => 'b']]]));
    }

    public function testMeter(): void
    {
        $c = BlockConfig::validate(BlockType::Meter, ['prompt' => 'p', 'min' => 0, 'max' => 10, 'step' => 0.5, 'labels' => ['min' => 'low']]);
        self::assertSame(['min' => 'low'], $c['labels']);
        self::assertNotEmpty(self::errors(BlockType::Meter, ['prompt' => 'p', 'min' => 10, 'max' => 0, 'step' => 1]));
        self::assertNotEmpty(self::errors(BlockType::Meter, ['prompt' => 'p', 'min' => 0, 'max' => 1e9, 'step' => 1]));
        self::assertNotEmpty(self::errors(BlockType::Meter, ['prompt' => 'p', 'min' => 0, 'max' => 10, 'step' => '1']));
    }

    public function testPlotAxes(): void
    {
        $c = BlockConfig::validate(BlockType::Plot, ['x_axis' => ['label' => 'Effort', 'min' => 0, 'max' => 10], 'y_axis' => ['label' => 'Impact', 'min' => 0, 'max' => 10], 'items' => ['A']]);
        self::assertSame('Effort', $c['x_axis']['label']);
        self::assertNotEmpty(self::errors(BlockType::Plot, ['x_axis' => ['label' => 'x', 'min' => 5, 'max' => 5], 'y_axis' => ['label' => 'y', 'min' => 0, 'max' => 1], 'items' => ['A']]));
    }

    public function testQuizValidationAndAnswerKeyStripping(): void
    {
        $config = BlockConfig::validate(BlockType::Quiz, ['pacing' => 'tutor', 'questions' => [
            ['type' => 'single', 'prompt' => '2+2?', 'options' => ['3', '4'], 'correct_answer' => 'o2', 'time_limit_seconds' => 20],
            ['type' => 'multi', 'prompt' => 'Primes?', 'options' => ['2', '3', '4'], 'correct_answer' => ['o2', 'o1']],
            ['type' => 'true_false', 'prompt' => 'Sky blue?', 'correct_answer' => true],
            ['type' => 'numeric', 'prompt' => 'Pi?', 'correct_answer' => 3.14, 'tolerance' => 0.01, 'points' => 5],
        ]]);
        self::assertSame(['o1', 'o2'], $config['questions'][1]['correct_answer'], 'multi answers normalised (sorted)');
        self::assertSame('q1', $config['questions'][0]['id']);

        $view = BlockConfig::participantView(BlockType::Quiz, $config);
        $json = json_encode($view);
        self::assertStringNotContainsString('correct_answer', $json);
        self::assertStringNotContainsString('tolerance', $json);
        self::assertSame('2+2?', $view['questions'][0]['prompt']);
    }

    public function testQuizRejectsInvalidAnswers(): void
    {
        self::assertNotEmpty(self::errors(BlockType::Quiz, ['questions' => [['type' => 'single', 'prompt' => 'p', 'options' => ['a', 'b'], 'correct_answer' => 'zz']]]));
        self::assertNotEmpty(self::errors(BlockType::Quiz, ['questions' => [['type' => 'multi', 'prompt' => 'p', 'options' => ['a', 'b'], 'correct_answer' => []]]]));
        self::assertNotEmpty(self::errors(BlockType::Quiz, ['questions' => [['type' => 'true_false', 'prompt' => 'p', 'correct_answer' => 'yes']]]));
        self::assertNotEmpty(self::errors(BlockType::Quiz, ['questions' => [['type' => 'numeric', 'prompt' => 'p', 'correct_answer' => NAN]]]));
        self::assertNotEmpty(self::errors(BlockType::Quiz, ['questions' => [['type' => 'free_text', 'prompt' => 'p']]]), 'free-text deferred to v2');
        self::assertNotEmpty(self::errors(BlockType::Quiz, ['questions' => [
            ['id' => 'a', 'type' => 'true_false', 'prompt' => 'p', 'correct_answer' => true],
            ['id' => 'a', 'type' => 'true_false', 'prompt' => 'p', 'correct_answer' => true],
        ]]));
    }

    public function testNonQuizViewUnchanged(): void
    {
        $c = BlockConfig::validate(BlockType::Write, ['prompt' => 'Reflect']);
        self::assertSame($c, BlockConfig::participantView(BlockType::Write, $c));
    }

    public function testSizeLimits(): void
    {
        self::assertNotEmpty(self::errors(BlockType::Write, ['prompt' => str_repeat('x', 1001)]));
        self::assertNotEmpty(self::errors(BlockType::Rank, ['items' => array_fill(0, 51, 'x')]));
        self::assertNotEmpty(self::errors(BlockType::Write, ['prompt' => str_repeat('x', 70000)]));
    }

    public function testEveryTypeHasAValidMinimalConfig(): void
    {
        $minimal = [
            'slide' => [], 'whiteboard' => [], 'annotate' => ['prompt' => 'p', 'tags' => ['a']],
            'quiz' => ['questions' => [['type' => 'true_false', 'prompt' => 'p', 'correct_answer' => false]]],
            'poll' => ['question' => 'q', 'options' => ['a', 'b']], 'meter' => ['prompt' => 'p', 'min' => 0, 'max' => 1, 'step' => 1],
            'rate' => ['items' => ['a']], 'rank' => ['items' => ['a', 'b']], 'word' => ['items' => ['a', 'b']],
            'plot' => ['x_axis' => ['label' => 'x', 'min' => 0, 'max' => 1], 'y_axis' => ['label' => 'y', 'min' => 0, 'max' => 1], 'items' => ['a']],
            'word_cloud' => ['prompt' => 'p'], 'write' => ['prompt' => 'p'], 'wall' => ['columns' => ['Ideas']],
        ];
        foreach (BlockType::cases() as $t) {
            self::assertSame([], self::errors($t, $minimal[$t->value]), $t->value);
        }
    }
}
