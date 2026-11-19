<?php

declare(strict_types=1);

namespace FancySign\Data;

use FancySign\Seal\Ed25519;
use FancySign\Support\CanonicalJson;

/**
 * The data-mode completion record (CC-fancy-0052 section 4.3):
 * `fancy-sign.completion` VERSION 2, token `fsig_v2_`. It is the reported fact
 * "every signer has signed": it binds the signature records together under one
 * chain head and adds no claim the records do not already make.
 *
 * A separate class from {@see \FancySign\Completion\Completion} (version 1, the
 * pdf record) so that v1 and v2 can never be confused, and the pdf path stays
 * byte for byte what it was. Closed key sets, sealed the same way.
 */
final class DataCompletion
{
    public const TYPE = 'fancy-sign.completion';

    public const VERSION = 2;

    public const TOKEN_PREFIX = 'fsig_v2_';

    public const KEYS = ['type', 'version', 'mode', 'envelope_id', 'data_sha256', 'data_bytes', 'canonical', 'profile', 'fields_sha256',
        'signers', 'chain_head', 'nonce', 'key_id'];

    /** Each signer: who, when, how, and the token of their signature record. */
    public const SIGNER_KEYS = ['id', 'name', 'email', 'signed_at', 'method', 'otp_seq', 'consent_seq', 'signature'];

    /**
     * @param  array<string, mixed>  $fields  every key in KEYS except type, version, mode and nonce
     * @return array<string, mixed>
     */
    public static function issue(array $fields, string $secretB64): array
    {
        $fields += ['type' => self::TYPE, 'version' => self::VERSION, 'mode' => 'data', 'nonce' => bin2hex(random_bytes(16))];
        $record = [];
        foreach (self::KEYS as $k) {
            $record[$k] = $fields[$k] ?? null;
        }
        $record['token'] = self::tokenFor($record);
        $record['seal'] = self::seal($record, $secretB64);

        return $record;
    }

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

    /** Is this shaped like a version-2 completion record? (Not: is it genuine.) */
    public static function wellFormed(mixed $r): bool
    {
        if (! is_array($r) || ($r['type'] ?? null) !== self::TYPE || ($r['version'] ?? null) !== self::VERSION || ($r['mode'] ?? null) !== 'data') {
            return false;
        }
        $keys = array_keys($r);
        if (array_diff($keys, [...self::KEYS, 'token', 'seal']) !== [] || array_diff(self::KEYS, $keys) !== []) {
            return false;
        }
        foreach (['data_sha256', 'fields_sha256', 'chain_head'] as $k) {
            if (! is_string($r[$k]) || preg_match('/^[0-9a-f]{64}$/', $r[$k]) !== 1) {
                return false;
            }
        }
        if (! is_int($r['data_bytes']) || $r['data_bytes'] < 2 || ! is_string($r['canonical']) || ($r['profile'] !== null && ! is_string($r['profile']))
            || ! is_string($r['envelope_id']) || ! is_string($r['nonce']) || ! is_string($r['key_id'])
            || ! is_string($r['token'] ?? null) || ! is_string($r['seal'] ?? null)
            || ! is_array($r['signers']) || ! array_is_list($r['signers']) || $r['signers'] === []) {
            return false;
        }
        foreach ($r['signers'] as $s) {
            if (! is_array($s) || array_keys($s) !== self::SIGNER_KEYS || ! is_string($s['id']) || ! is_string($s['signature'])
                || ! str_starts_with($s['signature'], SignatureRecord::TOKEN_PREFIX)) {
                return false;
            }
        }

        return true;
    }
}
