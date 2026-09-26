<?php

declare(strict_types=1);

namespace app\components;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/** UTC wall clock for JWT time validation (PSR-20). */
final class JwtClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
