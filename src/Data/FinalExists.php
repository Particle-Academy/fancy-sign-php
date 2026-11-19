<?php

declare(strict_types=1);

namespace FancySign\Data;

/**
 * @internal Thrown inside the append by Signing::attachFinal() when the SAME
 * final is already registered, so no second event is written and the stored
 * record is returned instead. Never leaves Signing.
 */
final class FinalExists extends \RuntimeException
{
}
