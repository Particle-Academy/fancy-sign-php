<?php

declare(strict_types=1);

namespace FancySign\Data;

use FancySign\Seal\Ed25519;
use FancySign\Support\CanonicalJson;

/**
 * The registration of the host's final PDF in data mode (CC-fancy-0052
 * section 5.3): `fancy-sign.final`, version 1, token `fsig_fin_v1_`. Issued
 * once by `Signing::attachFinal()`, after completion.
 *
 * It proves the host registered THIS PDF hash for THIS completed signing, at
 * this time, once. It does not prove the PDF renders the data faithfully: the
 * package never reads or stores the PDF.
 *
 * Modelled on {@see SignatureRecord}: a closed key set, the token is sha256 of
 * the canonical bytes (CanonicalJson, floats refused), the seal an Ed25519
 * signature by the host's seal key over the same bytes. `chain_head` is the
 * hash of the `document.finalized` event that registered it.
 */
final class FinalRecord
{
    public const TYPE = 'fancy-sign.final';

    public const VERSION = 1;

    public const TOKEN_PREFIX = 'fsig_fin_v1_';

    /** Every key the record has, besides `token` and `seal`. */
    public const KEYS = [
        'type', 'version', 'envelope_id', 'completion_token', 'final_sha256', 'final_bytes', 'attached_at', 'attached_by',
        'chain_head', 'nonce', 'key_id',
    ];

    /**
     * @param  array<string, mixed>  $fields  every key in KEYS except type, version and nonce
     * @return array<string, mixed>
     */
    public static function issue(array $fields, string $secretB64): array
    {
        $fields += ['type' => self::TYPE, 'version' => self::VERSION, 'nonce' => bin2hex(random_bytes(16))];
        $record = [];
        foreach (self::KEYS as $k) {
            $record[$k] = $fields[$k] ?? null;
        }
        $record['token'] = self::tokenFor($record);
        $record['seal'] = self::seal($record, $secretB64);

        return $record;
    }

    /** The bytes the token hashes and the seal signs. */
    public static function canonical(array $record): string
    {
        return CanonicalJson::encode(array_intersect_key($record, array_flip(self::KEYS)));
    }

    public static function tokenFor(array $record): string
    {
        return self::TOKEN_PREFIX.hash('sha256', self::canonical($record));
    }

    public static function seal(array $record, string $secretB64): string
    {
        return Ed25519::sign(self::canonical($record), $secretB64);
    }

    /** Is this shaped like a final record? (Not: is it genuine.) */
    public static function wellFormed(mixed $r): bool
    {
        if (! is_array($r) || ($r['type'] ?? null) !== self::TYPE || ($r['version'] ?? null) !== self::VERSION) {
            return false;
        }
        $keys = array_keys($r);
        if (array_diff($keys, [...self::KEYS, 'token', 'seal']) !== [] || array_diff(self::KEYS, $keys) !== []) {
            return false;
        }
        foreach (['final_sha256', 'chain_head'] as $k) {
            if (! is_string($r[$k]) || preg_match('/^[0-9a-f]{64}$/', $r[$k]) !== 1) {
                return false;
            }
        }

        return is_int($r['final_bytes']) && $r['final_bytes'] >= 1
            && is_string($r['envelope_id']) && is_string($r['completion_token']) && str_starts_with($r['completion_token'], DataCompletion::TOKEN_PREFIX)
            && is_string($r['attached_at']) && is_string($r['attached_by']) && is_string($r['nonce']) && is_string($r['key_id'])
            && is_string($r['token'] ?? null) && is_string($r['seal'] ?? null);
    }
}
