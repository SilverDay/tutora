<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Activity\Aggregator;
use Tutora\Activity\SubmissionValidator;
use Tutora\Block\BlockConfig;
use Tutora\Block\BlockType;
use Tutora\Support\ValidationException;

final class SubmissionValidatorTest extends TestCase
{
    private static function cfg(BlockType $t, array $c): array
    {
        return BlockConfig::validate($t, $c);
    }

    private static function rejects(BlockType $t, array $config, array $payload): void
    {
        try {
            SubmissionValidator::validate($t, $config, $payload);
            self::fail('expected rejection of ' . json_encode($payload));
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    public function testPoll(): void
    {
        $c = self::cfg(BlockType::Poll, ['question' => 'q', 'options' => ['a', 'b', 'c'], 'max_selections' => 2]);
        self::assertSame(['selected' => ['o1', 'o3']], SubmissionValidator::validate(BlockType::Poll, $c, ['selected' => ['o1', 'o3']]));
        self::rejects(BlockType::Poll, $c, ['selected' => ['o1', 'o2', 'o3']]);
        self::rejects(BlockType::Poll, $c, ['selected' => []]);
        self::rejects(BlockType::Poll, $c, ['selected' => ['o1', 'o1']]);
        self::rejects(BlockType::Poll, $c, ['selected' => ['zz']]);
        self::rejects(BlockType::Poll, $c, ['selected' => ['o1'], 'extra' => 1]);
        self::rejects(BlockType::Poll, $c, ['selected' => 'o1']);
    }

    public function testMeterStepsAndRange(): void
    {
        $c = self::cfg(BlockType::Meter, ['prompt' => 'p', 'min' => 0, 'max' => 10, 'step' => 0.5]);
        self::assertSame(['value' => 7.5], SubmissionValidator::validate(BlockType::Meter, $c, ['value' => 7.5]));
        self::rejects(BlockType::Meter, $c, ['value' => 7.3]);
        self::rejects(BlockType::Meter, $c, ['value' => 11]);
        self::rejects(BlockType::Meter, $c, ['value' => '5']);
    }

    public function testRateRankWordPlot(): void
    {
        $rate = self::cfg(BlockType::Rate, ['items' => ['a', 'b'], 'scale' => 5]);
        self::assertSame(['ratings' => ['i1' => 5]], SubmissionValidator::validate(BlockType::Rate, $rate, ['ratings' => ['i1' => 5]]));
        self::rejects(BlockType::Rate, $rate, ['ratings' => ['i1' => 6]]);
        self::rejects(BlockType::Rate, $rate, ['ratings' => ['zz' => 1]]);

        $rank = self::cfg(BlockType::Rank, ['items' => ['a', 'b', 'c']]);
        self::assertSame(['order' => ['i3', 'i1', 'i2']], SubmissionValidator::validate(BlockType::Rank, $rank, ['order' => ['i3', 'i1', 'i2']]));
        self::rejects(BlockType::Rank, $rank, ['order' => ['i3', 'i1']]);
        self::rejects(BlockType::Rank, $rank, ['order' => ['i1', 'i1', 'i2']]);

        $word = self::cfg(BlockType::Word, ['items' => ['a', 'b', 'c'], 'max_selections' => 2]);
        self::rejects(BlockType::Word, $word, ['selected' => ['i1', 'i2', 'i3']]);

        $plot = self::cfg(BlockType::Plot, ['x_axis' => ['label' => 'x', 'min' => 0, 'max' => 10], 'y_axis' => ['label' => 'y', 'min' => -5, 'max' => 5], 'items' => ['a', 'b']]);
        self::assertCount(2, SubmissionValidator::validate(BlockType::Plot, $plot, ['points' => [['item_id' => 'i1', 'x' => 1, 'y' => -5], ['item_id' => 'i2', 'x' => 10, 'y' => 0.5]]])['points']);
        self::rejects(BlockType::Plot, $plot, ['points' => [['item_id' => 'i1', 'x' => 11, 'y' => 0]]]);
        self::rejects(BlockType::Plot, $plot, ['points' => [['item_id' => 'i1', 'x' => 1, 'y' => 0], ['item_id' => 'i1', 'x' => 2, 'y' => 0]]]);
    }

    public function testWordCloudCleansAndDeduplicates(): void
    {
        $c = self::cfg(BlockType::WordCloud, ['prompt' => 'p', 'max_words_per_participant' => 3]);
        $out = SubmissionValidator::validate(BlockType::WordCloud, $c, ['words' => ['  Secure  ', 'secure', "Ev\u{202E}il"]]);
        self::assertSame(['Secure', 'Evil'], $out['words']);
        self::rejects(BlockType::WordCloud, $c, ['words' => ['a', 'b', 'c', 'd']]);
        self::rejects(BlockType::WordCloud, $c, ['words' => [str_repeat('x', 41)]]);
        self::rejects(BlockType::WordCloud, $c, ['words' => ["\x00\x01"]]);
    }

    public function testWriteKeepsNewlinesStripsControls(): void
    {
        $c = self::cfg(BlockType::Write, ['prompt' => 'p']);
        self::assertSame(['text' => "line1\nline2"], SubmissionValidator::validate(BlockType::Write, $c, ['text' => "line1\r\nline2\x07"]));
        self::rejects(BlockType::Write, $c, ['text' => str_repeat('x', 2001)]);
        self::rejects(BlockType::Write, $c, ['text' => '   ']);
    }

    public function testNonSubmissionBlocksRejected(): void
    {
        self::rejects(BlockType::Quiz, [], ['x' => 1]);
        self::rejects(BlockType::Wall, [], ['x' => 1]);
    }

    public function testAggregates(): void
    {
        $poll = self::cfg(BlockType::Poll, ['question' => 'q', 'options' => ['a', 'b'], 'max_selections' => 2]);
        $agg = Aggregator::aggregate(BlockType::Poll, $poll, [['selected' => ['o1']], ['selected' => ['o1', 'o2']]]);
        self::assertSame(2, $agg['responses']);
        self::assertSame([2, 1], array_column($agg['options'], 'count'));
        self::assertSame([100.0, 50.0], array_column($agg['options'], 'percent'));

        $meter = self::cfg(BlockType::Meter, ['prompt' => 'p', 'min' => 0, 'max' => 4, 'step' => 1]);
        $agg = Aggregator::aggregate(BlockType::Meter, $meter, [['value' => 1], ['value' => 4], ['value' => 4]]);
        self::assertSame(3.0, $agg['mean']);
        self::assertSame(4, $agg['median']);
        self::assertSame([0, 1, 0, 0, 2], array_column($agg['distribution'], 'count'));

        $wide = self::cfg(BlockType::Meter, ['prompt' => 'p', 'min' => 0, 'max' => 100, 'step' => 1]);
        $agg = Aggregator::aggregate(BlockType::Meter, $wide, [['value' => 0], ['value' => 100], ['value' => 50]]);
        self::assertCount(20, $agg['distribution']);
        self::assertSame(3, array_sum(array_column($agg['distribution'], 'count')));

        // Borda: 3 items -> 2,1,0 points
        $rank = self::cfg(BlockType::Rank, ['items' => ['a', 'b', 'c']]);
        $agg = Aggregator::aggregate(BlockType::Rank, $rank, [['order' => ['i1', 'i2', 'i3']], ['order' => ['i2', 'i1', 'i3']], ['order' => ['i2', 'i3', 'i1']]]);
        self::assertSame(['i2', 'i1', 'i3'], array_column($agg['ranking'], 'id'));
        self::assertSame([5, 3, 1], array_column($agg['ranking'], 'points'));

        $rate = self::cfg(BlockType::Rate, ['items' => ['a'], 'scale' => 3]);
        $agg = Aggregator::aggregate(BlockType::Rate, $rate, [['ratings' => ['i1' => 3]], ['ratings' => ['i1' => 2]]]);
        self::assertSame(2.5, $agg['items'][0]['average']);
        self::assertSame([0, 1, 1], $agg['items'][0]['distribution']);

        $cloud = self::cfg(BlockType::WordCloud, ['prompt' => 'p']);
        $agg = Aggregator::aggregate(BlockType::WordCloud, $cloud, [['words' => ['Zero Trust', 'MFA']], ['words' => ['zero trust']]]);
        self::assertSame(['word' => 'Zero Trust', 'count' => 2], $agg['words'][0]);

        $plot = self::cfg(BlockType::Plot, ['x_axis' => ['label' => 'x', 'min' => 0, 'max' => 10], 'y_axis' => ['label' => 'y', 'min' => 0, 'max' => 10], 'items' => ['a']]);
        $agg = Aggregator::aggregate(BlockType::Plot, $plot, [['points' => [['item_id' => 'i1', 'x' => 2, 'y' => 4]]], ['points' => [['item_id' => 'i1', 'x' => 4, 'y' => 8]]]]);
        self::assertSame(['x' => 3.0, 'y' => 6.0], $agg['items'][0]['centroid']);
    }

    public function testWriteAggregateContainsNoText(): void
    {
        $agg = Aggregator::aggregate(BlockType::Write, ['prompt' => 'p'], [['text' => 'private thought']]);
        self::assertSame(['responses' => 1], $agg);
    }
}
