<?php

declare(strict_types=1);

namespace FancySign\Data;

use FancySign\Seal\Ed25519;
use FancySign\Support\CanonicalJson;

/**
 * The sealed record of ONE signature in data mode (CC-fancy-0052 section 4.2):
 * `fancy-sign.signature`, version 1, token `fsig_sig_v1_`. The unit of the
 * package's output in data mode: a host can prove signer 1 signed while signer
 * 2 has not, or will never be invited.
 *
 * Modelled on {@see \FancySign\Completion\Completion}: a closed key set, the
 * token is sha256 of the canonical bytes (CanonicalJson, floats refused), the
 * seal an Ed25519 signature by the host's seal key over the same bytes. The mark
 * itself is not in the record (personal and large); its hash is.
 *
 * `renderer` and `rendered_seq` copy the signer's latest `document.rendered`
 * CLAIM, so a reader of the record alone sees it. It stays a claim.
 */
final class SignatureRecord
{
    public const TYPE = 'fancy-sign.signature';

    public const VERSION = 1;

    public const TOKEN_PREFIX = 'fsig_sig_v1_';

    /** Every key the record has, besides `token` and `seal`. */
    public const KEYS = [
        'type', 'version', 'envelope_id', 'signer_id', 'name', 'email', 'signed_at', 'method', 'otp_seq', 'consent_seq',
        'rendered_seq', 'renderer', 'data_sha256', 'data_bytes', 'canonical', 'profile', 'fields_sha256', 'fields',
        'per_page_pages', 'mark_sha256', 'adoption_text_sha256', 'consent_text_sha256', 'step_up_sha256', 'chain_head',
        'nonce', 'key_id',
    ];

    /**
     * Build and seal a record.
     *
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

    /** Is this shaped like a version-1 signature record? (Not: is it genuine.) */
    public static function wellFormed(mixed $r): bool
    {
        if (! is_array($r) || ($r['type'] ?? null) !== self::TYPE || ($r['version'] ?? null) !== self::VERSION) {
            return false;
        }
        $keys = array_keys($r);
        if (array_diff($keys, [...self::KEYS, 'token', 'seal']) !== [] || array_diff(self::KEYS, $keys) !== []) {
            return false;
        }
        foreach (['data_sha256', 'fields_sha256', 'mark_sha256', 'adoption_text_sha256', 'consent_text_sha256', 'chain_head'] as $k) {
            if (! self::hex($r[$k])) {
                return false;
            }
        }
        foreach (['envelope_id', 'signer_id', 'name', 'email', 'signed_at', 'nonce', 'key_id', 'canonical', 'token', 'seal'] as $k) {
            if (! is_string($r[$k] ?? null)) {
                return false;
            }
        }

        return in_array($r['method'], ['otp', 'otp+passkey'], true)
            && is_int($r['otp_seq']) && is_int($r['consent_seq']) && is_int($r['data_bytes']) && $r['data_bytes'] >= 2
            && ($r['rendered_seq'] === null || is_int($r['rendered_seq']))
            && ($r['renderer'] === null || is_string($r['renderer']))
            && ($r['profile'] === null || is_string($r['profile']))
            && ($r['per_page_pages'] === null || (is_int($r['per_page_pages']) && $r['per_page_pages'] >= 1))
            && ($r['step_up_sha256'] === null || self::hex($r['step_up_sha256']))
            && is_array($r['fields']) && array_is_list($r['fields']) && array_filter($r['fields'], fn ($f) => ! is_string($f)) === []
            && str_starts_with($r['token'], self::TOKEN_PREFIX);
    }

    private static function hex(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9a-f]{64}$/', $v) === 1;
    }
}
