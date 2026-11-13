<?php

declare(strict_types=1);

namespace FancySign\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use FancySign\Support\Clock;

final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $at = '2026-11-13 12:00:00')
    {
        $this->now = new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }
}
