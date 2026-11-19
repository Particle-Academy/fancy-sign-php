<?php

declare(strict_types=1);

namespace FancySign\Data;

use stdClass;

/**
 * Reading INTO the host's data, which data mode otherwise never does. Two
 * uses, both at create and update: a field's RFC 6901 pointer must resolve
 * (a hint for the host, never used to place anything), and the optional
 * `last-word` profile refuses an image that is not inlined.
 *
 * The tree is the canonical bytes decoded with objects kept (stdClass).
 */
final class DataTree
{
    /** Does an RFC 6901 JSON Pointer resolve in this data? "" is the whole document. */
    public static function resolves(stdClass $root, string $pointer): bool
    {
        if ($pointer === '') {
            return true;
        }
        if ($pointer[0] !== '/') {
            return false;
        }
        $at = $root;
        foreach (explode('/', substr($pointer, 1)) as $raw) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $raw);
            if ($at instanceof stdClass) {
                if (! property_exists($at, $token)) {
                    return false;
                }
                $at = $at->{$token};
            } elseif (is_array($at)) {
                if (preg_match('/^(0|[1-9][0-9]*)$/', $token) !== 1 || (int) $token >= count($at)) {
                    return false;
                }
                $at = $at[(int) $token];
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Profile `last-word`: the pointer to the `src` of the first image that is
     * not a `data:` URI, or null. Walks `blocks`, nested `blocks` (quote, band
     * and the like) and table cells, depth-capped as fieldNodes() is. Nothing
     * else is checked: no schema, no layout.
     */
    public static function externalImage(stdClass $root): ?string
    {
        return is_array($root->blocks ?? null) ? self::blocks($root->blocks, '/blocks', 0) : null;
    }

    /** @param list<mixed> $blocks */
    private static function blocks(array $blocks, string $at, int $depth): ?string
    {
        if ($depth > 16) {
            return null;
        }
        foreach ($blocks as $i => $b) {
            if (! $b instanceof stdClass) {
                continue;
            }
            $here = "{$at}/{$i}";
            if (($b->type ?? null) === 'image' && ! (is_string($b->src ?? null) && str_starts_with($b->src, 'data:'))) {
                return "{$here}/src";
            }
            if (is_array($b->blocks ?? null) && ($found = self::blocks($b->blocks, "{$here}/blocks", $depth + 1)) !== null) {
                return $found;
            }
            foreach (is_array($b->rows ?? null) ? $b->rows : [] as $r => $row) {
                foreach ($row instanceof stdClass && is_array($row->cells ?? null) ? $row->cells : [] as $c => $cell) {
                    if ($cell instanceof stdClass && is_array($cell->blocks ?? null)
                        && ($found = self::blocks($cell->blocks, "{$here}/rows/{$r}/cells/{$c}/blocks", $depth + 1)) !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }
}
