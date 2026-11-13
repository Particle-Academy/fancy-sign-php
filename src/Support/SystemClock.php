<?php

declare(strict_types=1);

namespace FancySign\Support;

use DateTimeImmutable;
use DateTimeZone;

/** The real clock, in UTC. */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
