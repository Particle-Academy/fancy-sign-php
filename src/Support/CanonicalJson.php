<?php

declare(strict_types=1);

namespace FancySign\Support;

use InvalidArgumentException;
use stdClass;

/**
 * The one byte-exact JSON form that is hashed and sealed. The same scheme as
 * change-council's CanonicalJson (copied, not imported, so this package depends
 * on nothing of the council), so a Node port reproduces it byte for byte:
 *
 * - object keys sorted by byte order, recursively; list order kept
 * - no whitespace; `/` and non-ASCII unescaped; U+2028/2029 unescaped
 *   (JSON.stringify does not escape them either)
 * - floats refused: PHP and JavaScript do not print them the same way
 * - an empty PHP array is a list `[]`; an empty object must be a stdClass
 */
final class CanonicalJson
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        return json_encode(self::normalise($value), self::FLAGS);
    }

    /**
     * A stdClass is ALWAYS an object, whatever its keys: `{"0": "a"}` decoded
     * with assoc=false stays an object, as it is in JavaScript. A PHP array is
     * a list only when it is one (`array_is_list`), and otherwise an object,
     * built as a stdClass so that sorting cannot turn it into a list.
     */
    private static function normalise(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Canonical JSON refuses floats; use an integer or a string.');
        }
        if ($value instanceof stdClass) {
            return self::object(get_object_vars($value));
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalise(...), $value);
        }

        return self::object($value);
    }

    /** @param array<array-key, mixed> $members */
    private static function object(array $members): stdClass
    {
        ksort($members, SORT_STRING);
        $out = new stdClass;
        foreach ($members as $key => $item) {
            $out->{(string) $key} = self::normalise($item);
        }

        return $out;
    }
}
