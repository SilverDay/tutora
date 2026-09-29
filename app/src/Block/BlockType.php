<?php

declare(strict_types=1);

namespace Tutora\Block;

enum BlockType: string
{
    case Slide = 'slide';
    case Whiteboard = 'whiteboard';
    case Annotate = 'annotate';
    case Quiz = 'quiz';
    case Poll = 'poll';
    case Meter = 'meter';
    case Rate = 'rate';
    case Rank = 'rank';
    case Word = 'word';
    case Plot = 'plot';
    case WordCloud = 'word_cloud';
    case Write = 'write';
    case Wall = 'wall';

    /** Types whose participant input goes to the generic block_submissions table. */
    public function usesGenericSubmissions(): bool
    {
        return in_array($this, [self::Poll, self::Meter, self::Rate, self::Rank, self::Word, self::Plot, self::WordCloud, self::Write], true);
    }
}
