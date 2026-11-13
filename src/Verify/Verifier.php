<?php

declare(strict_types=1);

namespace FancySign\Verify;

use FancySign\Chain\EventChain;
use FancySign\Completion\Completion;
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
}
