<?php

declare(strict_types=1);

namespace FancySign\Support;

use DateTimeImmutable;

interface Clock
{
    /** Now, in UTC. */
    public function now(): DateTimeImmutable;
}
