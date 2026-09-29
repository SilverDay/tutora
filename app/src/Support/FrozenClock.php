<?php

declare(strict_types=1);

namespace Tutora\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/** Test clock. */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $time = '2026-01-01 12:00:00')
    {
        $this->now = new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->add(new DateInterval($interval));
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
