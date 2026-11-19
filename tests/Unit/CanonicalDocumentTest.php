<?php

declare(strict_types=1);

use FancySign\Data\CanonicalDocument;
use FancySign\Support\CanonicalJson;

/*
 * fsig-data-1 (CC-fancy-0052, plan section 2): the sign/chain canonical JSON
 * with ONE change, non-integral numbers print as ECMAScript Number::toString.
 *
 * The thirteen vectors were computed by two independent design sketches (Node
 * and PHP) before this class existed. These hashes are typed from the plan, not
 * from this implementation: a disagreement is a bug to find, never a golden to
 * regenerate.
 */

const FSD_EXPECTED = [
    'd0001' => ['{"a":[true,null,"x"],"b":1}', '54a65415ad370228851a1da4b31b6fd42dc58b19a50d35cae759325f7388ce64'],
    'd0002' => ['{"letterSpacing":-0.25,"lineHeight":1.15,"ratio":0.1,"size":10.5,"third":0.3333333333333333}', '98b86554ecebe2970f99b56d2e50d5f1689b5dc3b303386611f9686b239f8a35'],
    'd0003' => ['{"a":12,"b":0,"c":100,"d":9007199254740991}', '85d4d2b2857c8324f03f9014cefab788061bb711e98e90907094bbe230c06773'],
    'd0004' => ['{"edge":0.000001,"neg":-1.5e-9,"tiny":1e-7}', '6d8d77e96e1c2c242f29df0eabf3b65efeb63d38180abec7e4f19c3429199239'],
    'd0005' => ['{"blocks":[],"cells":{"0":"a","1":"b"},"meta":{}}', '12d7181d17c7f0d3c62cfd50a803e3409a22b2224ad34b780250824128b774b8'],
    'd0006' => [null, '899c1d56db1bd28d53bbfa9829ef466c8796c2add39a7bbc066f47b22caf130c'],
    'd0007' => ['{"currency":"USD","customer":{"name":"Pat Buyer","tags":[]},"lines":[{"discount":0.05,"qty":2,"sku":"W-1","unit":612.5},{"options":{},"qty":1,"sku":"S-9","unit":25}],"quote":"Q-1001","tax_rate":0.0825,"terms":["Net 30","Valid 14 days"],"totals":{"net":1250,"tax":103.125}}', '9f3a6327d5c426994325ae9037518f595c604153944915a21236aa728e9ed2a9'],
    'd0008' => null,
    'd0009' => null,
    'd0010' => null,
    'd0011' => null,
    'd0012' => ['{"grid":[[0.5,1,[2.25,[]]],[{},null,-3.75]],"weights":[[0.1,0.2],[0.30000000000000004]]}', '64dcfd3985bebbc4edae7655cbadaf5078ea5d02c204f780e39dab41ba51d597'],
    'd0013' => ['{}', '44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a'],
];

/** d0006's canonical bytes, as hex (a rendered U+2028 is invisible). */
const FSD_D0006_HEX = '7b227a223a22612f62e280a8635c7530303031645c6e5c22715c22222c22c3a9223a332c22ee8080223a312c22f09f9880223a327d';

/** @return array<string, string> id => input JSON text */
function fsdVectors(): array
{
    $rows = json_decode((string) file_get_contents(dirname(__DIR__).'/Conformance/fsig-data-1-vectors.json'), true, 8, JSON_THROW_ON_ERROR);

    return array_column($rows, 'input', 'id');
}

/** The canonical text, or null when refused. */
function fsdRun(callable $impl, string $text): ?string
{
    try {
        return $impl($text);
    } catch (InvalidArgumentException|JsonException) {
        return null;
    }
}

/**
 * A mutable reading of plan section 2.2, used ONLY to prove the vectors catch
 * each plausible mistake. Its control must agree with CanonicalDocument on
 * every row, or the mutants below prove nothing.
 *
 * @param  array<string, bool>  $m
 */
function fsdProbe(array $m = []): callable
{
    $enc = function (mixed $v) use (&$enc, $m): string {
        $flags = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | (($m['escape'] ?? false) ? 0 : JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        if ($v === null || is_bool($v) || is_string($v)) {
            return json_encode($v, $flags);
        }
        if (is_int($v) || (is_float($v) && floor($v) === $v)) {
            if (abs((float) $v) > 9007199254740991 && ! ($m['unsafe'] ?? false)) {
                throw new InvalidArgumentException('unsafe');
            }
            if (($m['negZero'] ?? false) && is_float($v) && $v === 0.0 && fdiv(1, $v) < 0) {
                return '-0';
            }
            if (($m['phpFloat'] ?? false) && is_float($v)) {
                return json_encode($v, JSON_PRESERVE_ZERO_FRACTION);
            }

            return is_float($v) && abs($v) > PHP_INT_MAX ? sprintf('%.0f', $v) : (string) (int) $v;
        }
        if (is_float($v)) {
            if ($m['refuseFloats'] ?? false) {
                throw new InvalidArgumentException('float');
            }
            if ($m['phpFloat'] ?? false) {
                return json_encode($v);
            }
            if ($m['round'] ?? false) {
                return CanonicalDocument::number((float) sprintf('%.15g', $v));
            }

            return CanonicalDocument::number($v);
        }
        if (is_array($v) && array_is_list($v)) {
            return '['.implode(',', array_map($enc, $v)).']';
        }
        $members = is_array($v) ? $v : get_object_vars($v);
        $keys = array_map('strval', array_keys($members));
        if ($m['utf16'] ?? false) {
            usort($keys, fn (string $a, string $b) => strcmp(mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'), mb_convert_encoding($b, 'UTF-16BE', 'UTF-8')));
        } else {
            sort($keys, SORT_STRING);
        }
        $out = [];
        foreach ($keys as $k) {
            $out[] = json_encode($k, $flags).':'.$enc($members[$k]);
        }

        return '{'.implode(',', $out).'}';
    };

    return function (string $text) use ($enc, $m): string {
        $value = json_decode($text, $m['assoc'] ?? false, 64, JSON_THROW_ON_ERROR);
        if (($m['refuseEmpty'] ?? false) && $value instanceof stdClass && get_object_vars($value) === []) {
            throw new InvalidArgumentException('empty');
        }
        if (! $value instanceof stdClass && ! (($m['assoc'] ?? false) && is_array($value) && ! array_is_list($value)) && ! (($m['assoc'] ?? false) && $value === [])) {
            throw new InvalidArgumentException('top level');
        }

        return $enc($value);
    };
}

/** @return list<string> the vector ids an implementation gets wrong */
function fsdFailing(callable $impl): array
{
    $out = [];
    foreach (fsdVectors() as $id => $text) {
        $want = FSD_EXPECTED[$id];
        $got = fsdRun($impl, $text);
        $wantText = $want === null ? null : ($want[0] ?? hex2bin(FSD_D0006_HEX));
        if ($got !== $wantText) {
            $out[] = $id;
        }
    }

    return $out;
}

it('reproduces all thirteen plan vectors: canonical bytes, sha256 and length', function (): void {
    $vectors = fsdVectors();
    expect(array_keys($vectors))->toBe(array_keys(FSD_EXPECTED));
    foreach ($vectors as $id => $text) {
        $want = FSD_EXPECTED[$id];
        if ($want === null) {
            expect(fn () => CanonicalDocument::canonical($text))->toThrow(InvalidArgumentException::class);

            continue;
        }
        $bytes = CanonicalDocument::canonical($text);
        $expectedBytes = $want[0] ?? hex2bin(FSD_D0006_HEX);
        expect($bytes === $expectedBytes)->toBeTrue("{$id} canonical bytes differ: {$bytes}")
            ->and(CanonicalDocument::sha256($text))->toBe($want[1])
            ->and(hash('sha256', $bytes))->toBe($want[1]);
    }
    expect(strlen(CanonicalDocument::canonical($vectors['d0007'])))->toBe(272)
        ->and(strlen(CanonicalDocument::canonical($vectors['d0006'])))->toBe(53);
});

it('canonicalises a stdClass tree exactly as the same data as JSON text', function (): void {
    foreach (fsdVectors() as $id => $text) {
        if (FSD_EXPECTED[$id] === null || $id === 'd0011') {
            continue;
        }
        expect(CanonicalDocument::canonical(json_decode($text, false)))->toBe(CanonicalDocument::canonical($text));
    }
});

it('is idempotent: canonical bytes canonicalise to themselves', function (): void {
    foreach (fsdVectors() as $id => $text) {
        if (FSD_EXPECTED[$id] !== null) {
            $once = CanonicalDocument::canonical($text);
            expect(CanonicalDocument::canonical($once))->toBe($once);
        }
    }
});

it('refuses an associative PHP array: an object must arrive as JSON text or a stdClass', function (): void {
    expect(fn () => CanonicalDocument::canonical(['a' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CanonicalDocument::canonical((object) ['a' => ['b' => 1]]))->toThrow(InvalidArgumentException::class);
});

it('refuses data nested deeper than the limit, and the limit is a parameter', function (): void {
    $deep = str_repeat('{"a":', 10).'1'.str_repeat('}', 10);
    expect(CanonicalDocument::canonical($deep, 10))->toBe($deep)
        ->and(fn () => CanonicalDocument::canonical($deep, 9))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CanonicalDocument::canonical(json_decode($deep, false), 9))->toThrow(InvalidArgumentException::class);
});

it('refuses a non-finite number and invalid UTF-8 handed over as a stdClass', function (): void {
    expect(fn () => CanonicalDocument::canonical((object) ['x' => INF]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CanonicalDocument::canonical((object) ['x' => NAN]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CanonicalDocument::canonical((object) ['x' => "\xED\xA0\x80"]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CanonicalDocument::canonical((object) ["\xFF" => 1]))->toThrow(InvalidArgumentException::class);
});

it('prints numbers as ECMAScript Number::toString does, around the exponent edges', function (): void {
    expect(CanonicalDocument::number(1e-7))->toBe('1e-7')
        ->and(CanonicalDocument::number(0.000001))->toBe('0.000001')
        ->and(CanonicalDocument::number(1.5e-9))->toBe('1.5e-9')
        ->and(CanonicalDocument::number(-1.5e-9))->toBe('-1.5e-9')
        ->and(CanonicalDocument::number(123.456))->toBe('123.456')
        ->and(CanonicalDocument::number(0.1 + 0.2))->toBe('0.30000000000000004')
        ->and(CanonicalDocument::number(5e-324))->toBe('5e-324')
        ->and(CanonicalDocument::number(12.0))->toBe('12')
        ->and(CanonicalDocument::number(-0.0))->toBe('0');
});

// ------------------------------------------------------------ the mutants (plan 2.4)

it('THE CONTROL: the probe agrees with CanonicalDocument on every vector', function (): void {
    expect(fsdFailing(fsdProbe()))->toBe([])
        ->and(fsdFailing(fn (string $t) => CanonicalDocument::canonical($t)))->toBe([]);
});

it('catches PHP\'s own float form (1.0e-7, 12.0)', function (): void {
    expect(fsdFailing(fsdProbe(['phpFloat' => true])))->toContain('d0003')->toContain('d0004');
});

it('catches floats refused (today\'s CanonicalJson)', function (): void {
    expect(fsdFailing(fsdProbe(['refuseFloats' => true])))->toBe(['d0002', 'd0004', 'd0007', 'd0012'])
        ->and(fsdFailing(fn (string $t) => CanonicalJson::encode(json_decode($t, false, 64, JSON_THROW_ON_ERROR))))->toContain('d0002', 'd0004', 'd0007', 'd0012');
});

it('catches UTF-16 key order', function (): void {
    expect(fsdFailing(fsdProbe(['utf16' => true])))->toBe(['d0006']);
});

it('catches an assoc decode ({} read as [], {"0": ...} as a list)', function (): void {
    expect(fsdFailing(fsdProbe(['assoc' => true])))->toContain('d0005', 'd0007', 'd0012');
});

it('catches -0 printed as -0', function (): void {
    expect(fsdFailing(fsdProbe(['negZero' => true])))->toBe(['d0003']);
});

it('catches float noise rounded away (0.30000000000000004 to 0.3)', function (): void {
    expect(fsdFailing(fsdProbe(['round' => true])))->toContain('d0012');
});

it('catches unsafe integers accepted', function (): void {
    expect(fsdFailing(fsdProbe(['unsafe' => true])))->toBe(['d0008', 'd0009']);
});

it('catches escaped slashes or an escaped U+2028', function (): void {
    expect(fsdFailing(fsdProbe(['escape' => true])))->toBe(['d0006']);
});

it('catches {} refused as empty', function (): void {
    expect(fsdFailing(fsdProbe(['refuseEmpty' => true])))->toBe(['d0013']);
});
