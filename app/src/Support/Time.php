<?php

declare(strict_types=1);

namespace Tutora\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Conversions between DateTimeImmutable and MariaDB DATETIME(3) strings (always UTC). */
final class Time
{
    public static function toDb(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    public static function fromDb(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }
}
