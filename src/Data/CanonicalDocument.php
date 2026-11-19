<?php

declare(strict_types=1);

namespace FancySign\Data;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * `fsig-data-1`: the byte form of a data-mode envelope's data (CC-fancy-0052),
 * normative in fancy-conformance `suites/sign/SPEC.md` section 6.1 and pinned
 * by `sign/data`.
 *
 * It is `CanonicalJson` (sign/chain canonical JSON) with ONE change: a
 * non-integral number prints as ECMAScript `Number::toString` (RFC 8785
 * section 3.2.2.3) instead of being refused. In full:
 *
 * - the top level is an object (`{}` allowed); anything else is refused
 * - object keys sorted by UTF-8 byte order, recursively; list order kept; no
 *   whitespace; `/`, non-ASCII and U+2028/U+2029 raw; controls escaped
 * - an integral number prints as an integer (`12.0` is `12`, `-0.0` is `0`),
 *   refused beyond 2^53 - 1 (two runtimes would read different numbers)
 * - a non-integral number prints with the shortest round-trip digits, in the
 *   exponent form only below 1e-6 or from 1e21 (`1e-7`, never PHP's `1.0e-7`)
 * - strings and keys are well-formed UTF-8; a lone surrogate is refused
 *
 * The data arrives as JSON TEXT or a stdClass tree, never an associative
 * array: PHP's assoc decode cannot tell `{}` from `[]`, or `{"0": "a"}` from
 * `["a"]`, and those hash differently.
 *
 * Floats stay refused everywhere else (events, records, marks): those keep
 * `CanonicalJson`.
 */
final class CanonicalDocument
{
    /** The name recorded beside every hash made this way. */
    public const NAME = 'fsig-data-1';

    /** 2^53 - 1: the largest integer every runtime reads exactly. */
    public const MAX_SAFE = 9007199254740991;

    public const DEFAULT_MAX_DEPTH = 64;

    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    /**
     * The canonical bytes.
     *
     * @param  string|stdClass  $data  JSON text, or a tree decoded with objects kept (json_decode($text, false))
     * @param  int  $maxDepth  the deepest nesting of objects and lists allowed (the top object is 1)
     *
     * @throws InvalidArgumentException when the data cannot be canonicalised
     */
    public static function canonical(mixed $data, int $maxDepth = self::DEFAULT_MAX_DEPTH): string
    {
        if (is_string($data)) {
            try {
                $data = json_decode($data, false, max(1, $maxDepth) + 1, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new InvalidArgumentException('The data is not valid JSON: '.$e->getMessage().'.');
            }
        }
        if (! $data instanceof stdClass) {
            throw new InvalidArgumentException('The data must be a JSON object (JSON text or a stdClass), not '.get_debug_type($data).'.');
        }

        return self::encode($data, 1, $maxDepth, '');
    }

    /** sha256 hex of the canonical bytes: `data_sha256`. */
    public static function sha256(mixed $data, int $maxDepth = self::DEFAULT_MAX_DEPTH): string
    {
        return hash('sha256', self::canonical($data, $maxDepth));
    }

    /**
     * ECMAScript Number::toString of a finite double: what JavaScript's
     * `String(x)` prints. Integral values print as integers.
     */
    public static function number(float $x): string
    {
        if (! is_finite($x)) {
            throw new InvalidArgumentException('A number must be finite.');
        }
        if ($x === 0.0) {
            return '0';
        }
        // Shortest round-trip digits: json_encode under serialize_precision -1.
        $old = ini_set('serialize_precision', '-1');
        try {
            $repr = json_encode($x, JSON_THROW_ON_ERROR);
        } finally {
            ini_set('serialize_precision', $old === false ? '-1' : $old);
        }
        $neg = $repr[0] === '-';
        $repr = ltrim($repr, '-');
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $repr, $m) !== 1) {
            throw new InvalidArgumentException("Cannot print the number {$repr}.");
        }
        $all = $m[1].($m[2] ?? '');
        $stripped = ltrim($all, '0');
        // ECMA-262 6.1.6.1.20: digits s (k of them) and n with x = s * 10^(n - k).
        $n = strlen($m[1]) + (isset($m[3]) ? (int) $m[3] : 0) - (strlen($all) - strlen($stripped));
        $digits = rtrim($stripped, '0');
        $k = strlen($digits);
        if ($k <= $n && $n <= 21) {
            $s = $digits.str_repeat('0', $n - $k);
        } elseif (0 < $n && $n <= 21) {
            $s = substr($digits, 0, $n).'.'.substr($digits, $n);
        } elseif (-6 < $n && $n <= 0) {
            $s = '0.'.str_repeat('0', -$n).$digits;
        } else {
            $e = $n - 1;
            $s = $digits[0].($k > 1 ? '.'.substr($digits, 1) : '').'e'.($e >= 0 ? '+' : '-').abs($e);
        }

        return ($neg ? '-' : '').$s;
    }

    private static function encode(mixed $v, int $depth, int $maxDepth, string $at): string
    {
        if ($v === null || is_bool($v)) {
            return $v === null ? 'null' : ($v ? 'true' : 'false');
        }
        if (is_string($v)) {
            return self::string($v, $at);
        }
        if (is_int($v)) {
            if ($v > self::MAX_SAFE || $v < -self::MAX_SAFE) {
                throw new InvalidArgumentException("{$at}: the integer {$v} is beyond 2^53 - 1, which not every runtime reads exactly.");
            }

            return (string) $v;
        }
        if (is_float($v)) {
            if (! is_finite($v)) {
                throw new InvalidArgumentException("{$at}: a number must be finite.");
            }
            if (floor($v) === $v) {
                if (abs($v) > self::MAX_SAFE) {
                    throw new InvalidArgumentException("{$at}: the integral number is beyond 2^53 - 1, which not every runtime reads exactly.");
                }

                return (string) (int) $v;
            }

            return self::number($v);
        }
        if ($depth > $maxDepth) {
            throw new InvalidArgumentException("{$at}: the data is nested deeper than {$maxDepth} levels.");
        }
        if (is_array($v)) {
            if (! array_is_list($v)) {
                throw new InvalidArgumentException("{$at}: an object must be a stdClass, never an associative array.");
            }
            $out = [];
            foreach ($v as $i => $item) {
                $out[] = self::encode($item, $depth + 1, $maxDepth, "{$at}/{$i}");
            }

            return '['.implode(',', $out).']';
        }
        if ($v instanceof stdClass) {
            $members = [];
            foreach (get_object_vars($v) as $key => $item) {
                $members[(string) $key] = $item;
            }
            ksort($members, SORT_STRING);
            $out = [];
            foreach ($members as $key => $item) {
                $key = (string) $key;
                $out[] = self::string($key, $at).':'.self::encode($item, $depth + 1, $maxDepth, $at.'/'.str_replace(['~', '/'], ['~0', '~1'], $key));
            }

            return '{'.implode(',', $out).'}';
        }

        throw new InvalidArgumentException("{$at}: ".get_debug_type($v).' is not a JSON value.');
    }

    private static function string(string $s, string $at): string
    {
        if (! mb_check_encoding($s, 'UTF-8')) {
            throw new InvalidArgumentException("{$at}: a string or key is not well-formed Unicode (a lone surrogate, or invalid UTF-8).");
        }

        return json_encode($s, self::FLAGS);
    }
}
