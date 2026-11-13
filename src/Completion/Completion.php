<?php

declare(strict_types=1);

namespace FancySign\Completion;

use FancySign\Seal\Ed25519;
use FancySign\Support\CanonicalJson;

/**
 * The completion record: what was signed, by whom, and the chain head at the
 * moment of completion, in canonical JSON. The fancy-passkeys login-certificate
 * pattern: a token prefix binding schema, canonical form and hash
 * (`fsig_v1_<sha256 hex>`), a 16-byte nonce against enumeration, and a CLOSED
 * key set, so nothing credential-shaped can ride along.
 *
 * Unlike a login certificate this record IS sealed: an Ed25519 signature by the
 * host's seal key over the same canonical bytes the token hashes. Changing any
 * field breaks the token; recomputing the token breaks the seal.
 *
 * An SES seal is checked by OUR verifier and the host's public key. A PDF
 * reader shows no signature panel for it; that is phase 2 (PAdES).
 */
final class Completion
{
    public const TYPE = 'fancy-sign.completion';

    public const VERSION = 1;

    public const TOKEN_PREFIX = 'fsig_v1_';

    /** Every key the record may have, besides `token` and `seal`. */
    public const KEYS = ['type', 'version', 'envelope_id', 'as_sent_sha256', 'as_sent_bytes', 'final_sha256', 'signers', 'chain_head', 'nonce', 'key_id'];

    public const SIGNER_KEYS = ['id', 'name', 'email', 'signed_at', 'method', 'otp_seq', 'consent_seq'];

    /**
     * Build and seal a record.
     *
     * @param  array<string, mixed>  $fields  every key in KEYS except type, version and nonce
     * @return array<string, mixed>
     */
    public static function issue(array $fields, string $secretB64): array
    {
        $record = ['type' => self::TYPE, 'version' => self::VERSION] + $fields + ['nonce' => bin2hex(random_bytes(16))];
        $record = array_intersect_key($record, array_flip(self::KEYS));
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

    /** Is this shaped like a version-1 completion record? (Not: is it genuine.) */
    public static function wellFormed(mixed $record): bool
    {
        if (! is_array($record) || ($record['type'] ?? null) !== self::TYPE || ($record['version'] ?? null) !== self::VERSION) {
            return false;
        }
        $extra = array_diff(array_keys($record), [...self::KEYS, 'token', 'seal']);
        if ($extra !== [] || count(array_intersect(array_keys($record), self::KEYS)) !== count(self::KEYS)) {
            return false;
        }
        foreach (['as_sent_sha256', 'final_sha256', 'chain_head'] as $k) {
            if (! is_string($record[$k]) || preg_match('/^[0-9a-f]{64}$/', $record[$k]) !== 1) {
                return false;
            }
        }
        if (! is_int($record['as_sent_bytes']) || $record['as_sent_bytes'] < 1 || ! is_array($record['signers'])
            || ! is_string($record['token'] ?? null) || ! is_string($record['seal'] ?? null) || ! is_string($record['key_id'])) {
            return false;
        }
        foreach ($record['signers'] as $s) {
            if (! is_array($s) || array_diff(array_keys($s), self::SIGNER_KEYS) !== []) {
                return false;
            }
        }

        return true;
    }
}
