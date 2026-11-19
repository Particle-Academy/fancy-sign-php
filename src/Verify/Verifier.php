<?php

declare(strict_types=1);

namespace FancySign\Verify;

use FancySign\Chain\EventChain;
use FancySign\Completion\Completion;
use FancySign\Data\CanonicalDocument;
use FancySign\Data\DataCompletion;
use FancySign\Data\SignatureRecord;
use FancySign\Seal\Ed25519;

/**
 * verify(final.pdf, completion, events?) in this order, stopping at the first
 * failure and naming it:
 *
 *   completion_invalid   the record is not a version-1 completion record
 *   token_mismatch       the record changed after its token was computed
 *   seal_invalid         the seal does not verify under the key for key_id
 *   final_hash_mismatch  the PDF is not the one the record names
 *   prefix_mismatch      the as-sent bytes are not a prefix of the final PDF
 *   chain_broken         the events do not form an intact chain
 *   chain_head_mismatch  the chain is intact but is not the one the record sealed
 *
 * The prefix check needs nothing of ours: truncate the final PDF at
 * `as_sent_bytes` and hash it. It runs here for convenience.
 *
 * This takes no file paths and fetches nothing. A host serving the public
 * verify endpoint caps the upload size before calling it.
 */
final class Verifier
{
    /** @param array<string, string> $publicKeys key_id => base64 Ed25519 public key */
    public function __construct(private readonly array $publicKeys) {}

    /**
     * @param  list<array<string, mixed>>|null  $events
     * @return array{ok: bool, reason: ?string, checks: array{seal: ?bool, token: ?bool, final_hash: ?bool, prefix: ?bool, chain: ?bool}}
     */
    public function verify(string $pdf, mixed $completion, ?array $events = null): array
    {
        $checks = ['seal' => null, 'token' => null, 'final_hash' => null, 'prefix' => null, 'chain' => null];
        $fail = fn (string $reason, string $check) => ['ok' => false, 'reason' => $reason, 'checks' => [$check => false] + $checks];

        if (! Completion::wellFormed($completion)) {
            return ['ok' => false, 'reason' => 'completion_invalid', 'checks' => $checks];
        }
        if (! hash_equals(Completion::tokenFor($completion), $completion['token'])) {
            return $fail('token_mismatch', 'token');
        }
        $checks['token'] = true;
        $key = $this->publicKeys[$completion['key_id']] ?? null;
        if (! is_string($key) || ! Ed25519::verify(Completion::canonical($completion), $completion['seal'], $key)) {
            return ['ok' => false, 'reason' => 'seal_invalid', 'checks' => ['seal' => false] + $checks];
        }
        $checks['seal'] = true;
        if (! hash_equals($completion['final_sha256'], hash('sha256', $pdf))) {
            return ['ok' => false, 'reason' => 'final_hash_mismatch', 'checks' => ['final_hash' => false] + $checks];
        }
        $checks['final_hash'] = true;
        if (strlen($pdf) < $completion['as_sent_bytes'] || ! hash_equals($completion['as_sent_sha256'], hash('sha256', substr($pdf, 0, $completion['as_sent_bytes'])))) {
            return ['ok' => false, 'reason' => 'prefix_mismatch', 'checks' => ['prefix' => false] + $checks];
        }
        $checks['prefix'] = true;

        if ($events !== null) {
            $chain = EventChain::verify(array_values($events));
            $ours = array_filter($events, fn ($e) => is_array($e) && ($e['envelope_id'] ?? null) === $completion['envelope_id']);
            if (! $chain['ok'] || count($ours) !== count($events)) {
                return ['ok' => false, 'reason' => 'chain_broken', 'checks' => ['chain' => false] + $checks];
            }
            if (! in_array($completion['chain_head'], array_column($events, 'hash'), true)) {
                return ['ok' => false, 'reason' => 'chain_head_mismatch', 'checks' => ['chain' => false] + $checks];
            }
            $checks['chain'] = true;
        }

        return ['ok' => true, 'reason' => null, 'checks' => $checks];
    }

    /** How deep the data may nest when it is verified (the verifier knows no host config, so it is generous). */
    public const DATA_MAX_DEPTH = 512;

    /**
     * Data mode (CC-fancy-0052): the data, its version-2 completion record, and
     * optionally the events and the signature records. `$dataJson` is ANY JSON
     * text of the data, canonical or the host's own export; it is canonicalised
     * here (fsig-data-1 is idempotent). Stops at the first failure:
     *
     *   completion_invalid      not a well-formed version-2 record
     *   token_mismatch          the record changed after its token
     *   seal_invalid            the seal fails, or no published key for key_id
     *   data_invalid            the data cannot be canonicalised
     *   data_hash_mismatch      the canonical bytes are not data_sha256 / data_bytes
     *   signature_invalid       a supplied record is malformed, its token or seal fails, it disagrees
     *                           with the completion, or the completion does not list its token for its signer
     *   chain_broken            the events are not one intact chain of this envelope
     *   chain_head_mismatch     the completion's head is not in the chain
     *   signature_not_in_chain  a record's chain_head is not a signer.signed of its signer, or a
     *                           signature token (a record's, or one the completion lists) was never sealed on the chain
     *
     * @param  list<array<string, mixed>>|null  $events
     * @param  list<mixed>|null  $signatures
     * @return array{ok: bool, reason: ?string, checks: array{token: ?bool, seal: ?bool, data_hash: ?bool, signatures: ?bool, chain: ?bool}}
     */
    public function verifyData(string $dataJson, mixed $completion, ?array $events = null, ?array $signatures = null): array
    {
        $checks = ['token' => null, 'seal' => null, 'data_hash' => null, 'signatures' => null, 'chain' => null];
        $fail = function (string $reason, ?string $check) use (&$checks): array {
            if ($check !== null) {
                $checks[$check] = false;
            }

            return ['ok' => false, 'reason' => $reason, 'checks' => $checks];
        };
        if (! DataCompletion::wellFormed($completion)) {
            return $fail('completion_invalid', null);
        }
        if (! hash_equals(DataCompletion::tokenFor($completion), $completion['token'])) {
            return $fail('token_mismatch', 'token');
        }
        $checks['token'] = true;
        if (! $this->sealed(DataCompletion::canonical($completion), $completion)) {
            return $fail('seal_invalid', 'seal');
        }
        $checks['seal'] = true;
        $data = $this->dataCheck($dataJson, $completion);
        if ($data !== null) {
            return $fail($data, $data === 'data_hash_mismatch' ? 'data_hash' : null);
        }
        $checks['data_hash'] = true;

        $listed = array_column($completion['signers'], 'id', 'signature');
        if ($signatures !== null) {
            foreach ($signatures as $record) {
                if (! $this->recordValid($record) || ($listed[$record['token']] ?? null) !== $record['signer_id']) {
                    return $fail('signature_invalid', 'signatures');
                }
                foreach (['envelope_id', 'data_sha256', 'data_bytes', 'canonical', 'profile', 'fields_sha256'] as $k) {
                    if ($record[$k] !== $completion[$k]) {
                        return $fail('signature_invalid', 'signatures');
                    }
                }
            }
            $checks['signatures'] = true;
        }

        if ($events !== null) {
            if (! $this->chainOf($events, $completion['envelope_id'])) {
                return $fail('chain_broken', 'chain');
            }
            if (! in_array($completion['chain_head'], array_column($events, 'hash'), true)) {
                return $fail('chain_head_mismatch', 'chain');
            }
            $sealed = self::sealedTokens($events);
            foreach (array_keys($listed) as $token) {
                if (! isset($sealed[(string) $token])) {
                    return $fail('signature_not_in_chain', 'chain');
                }
            }
            foreach ($signatures ?? [] as $record) {
                if (! self::signedInChain($events, $record)) {
                    return $fail('signature_not_in_chain', 'chain');
                }
            }
            $checks['chain'] = true;
        }

        return ['ok' => true, 'reason' => null, 'checks' => $checks];
    }

    /**
     * ONE data-mode signature record, with no completion: proof that this
     * signer signed this data while others have not (or never will). Stops at
     * the first failure: signature_invalid (not well formed), token_mismatch,
     * seal_invalid, data_invalid, data_hash_mismatch, then with events
     * chain_broken and signature_not_in_chain.
     *
     * @param  list<array<string, mixed>>|null  $events
     * @return array{ok: bool, reason: ?string, checks: array{token: ?bool, seal: ?bool, data_hash: ?bool, chain: ?bool}}
     */
    public function verifySignature(string $dataJson, mixed $record, ?array $events = null): array
    {
        $checks = ['token' => null, 'seal' => null, 'data_hash' => null, 'chain' => null];
        $fail = function (string $reason, ?string $check) use (&$checks): array {
            if ($check !== null) {
                $checks[$check] = false;
            }

            return ['ok' => false, 'reason' => $reason, 'checks' => $checks];
        };
        if (! SignatureRecord::wellFormed($record)) {
            return $fail('signature_invalid', null);
        }
        if (! hash_equals(SignatureRecord::tokenFor($record), $record['token'])) {
            return $fail('token_mismatch', 'token');
        }
        $checks['token'] = true;
        if (! $this->sealed(SignatureRecord::canonical($record), $record)) {
            return $fail('seal_invalid', 'seal');
        }
        $checks['seal'] = true;
        $data = $this->dataCheck($dataJson, $record);
        if ($data !== null) {
            return $fail($data, $data === 'data_hash_mismatch' ? 'data_hash' : null);
        }
        $checks['data_hash'] = true;
        if ($events !== null) {
            if (! $this->chainOf($events, $record['envelope_id'])) {
                return $fail('chain_broken', 'chain');
            }
            if (! isset(self::sealedTokens($events)[$record['token']]) || ! self::signedInChain($events, $record)) {
                return $fail('signature_not_in_chain', 'chain');
            }
            $checks['chain'] = true;
        }

        return ['ok' => true, 'reason' => null, 'checks' => $checks];
    }

    /** Token, seal and shape of one record, as verifyData() checks a supplied one. */
    private function recordValid(mixed $record): bool
    {
        return SignatureRecord::wellFormed($record) && hash_equals(SignatureRecord::tokenFor($record), $record['token'])
            && $this->sealed(SignatureRecord::canonical($record), $record);
    }

    /** @param array<string, mixed> $record a record with key_id and seal */
    private function sealed(string $canonical, array $record): bool
    {
        $key = $this->publicKeys[$record['key_id']] ?? null;

        return is_string($key) && Ed25519::verify($canonical, $record['seal'], $key);
    }

    /** Null when the data canonicalises to the record's hash and length; otherwise the reason. */
    private function dataCheck(string $dataJson, array $record): ?string
    {
        try {
            $bytes = CanonicalDocument::canonical($dataJson, self::DATA_MAX_DEPTH);
        } catch (\InvalidArgumentException) {
            return 'data_invalid';
        }

        return hash_equals($record['data_sha256'], hash('sha256', $bytes)) && strlen($bytes) === $record['data_bytes'] ? null : 'data_hash_mismatch';
    }

    /** An intact chain, every row of this envelope. */
    private function chainOf(array $events, string $envelopeId): bool
    {
        $events = array_values($events);
        $ours = array_filter($events, fn ($e) => is_array($e) && ($e['envelope_id'] ?? null) === $envelopeId);

        return EventChain::verify($events)['ok'] && count($ours) === count($events);
    }

    /** @return array<string, true> every token a signature.sealed event put on the chain */
    private static function sealedTokens(array $events): array
    {
        $out = [];
        foreach ($events as $e) {
            if (($e['type'] ?? null) === 'signature.sealed' && is_string($token = self::dataOf($e)['token'] ?? null)) {
                $out[$token] = true;
            }
        }

        return $out;
    }

    /** Is the record's chain_head the hash of a signer.signed event by its signer? */
    private static function signedInChain(array $events, array $record): bool
    {
        foreach ($events as $e) {
            if (($e['hash'] ?? null) === $record['chain_head']) {
                return ($e['type'] ?? null) === 'signer.signed' && ($e['actor'] ?? null) === 'signer:'.$record['signer_id'];
            }
        }

        return false;
    }

    /** An event's data as an array, however the store or the upload held it. */
    private static function dataOf(array $e): array
    {
        $d = $e['data'] ?? [];
        if (is_string($d)) {
            $d = json_decode($d, true);
        }

        return is_array($d) ? $d : (is_object($d) ? json_decode((string) json_encode($d), true) ?? [] : []);
    }
}
