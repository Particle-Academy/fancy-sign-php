<?php

declare(strict_types=1);

namespace FancySign\Chain;

use FancySign\Support\CanonicalJson;
use InvalidArgumentException;
use stdClass;

/**
 * One append-only, hash-chained log PER ENVELOPE, so an envelope's evidence is
 * self-contained and exportable. change-council's scheme, with envelope_id in
 * place of change_id and the request's source, IP and user agent hashed in:
 *
 *   hash = sha256(prev_hash . "\n" . canonical({seq, envelope_id, at, type, actor, source, ip, user_agent, data}))
 *
 * The genesis is 64 zeros. `verify()` reads raw rows and names the first
 * problem: `sequence_gap`, `broken_link` or `hash_mismatch`.
 *
 * `source` is `client` only for `page.viewed` and `document.rendered`, which
 * the signer's browser reports: claims, labelled so wherever they are shown.
 */
final class EventChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** The closed event set (plan section 5.4). */
    public const TYPES = [
        'envelope.created', 'envelope.sent', 'email.sent', 'link.opened', 'otp.sent', 'otp.verified', 'otp.failed',
        'otp.locked', 'consent.accepted', 'document.opened', 'page.viewed', 'field.completed', 'mark.adopted',
        'signer.signed', 'signer.declined', 'signer.requested_changes', 'reminder.sent', 'envelope.completed', 'envelope.voided', 'envelope.expired',
        'document.downloaded', 'seal.applied',
        // CC-fancy-0052, data mode: the browser's rendering claim, and each signature record's token.
        'document.rendered', 'signature.sealed',
    ];

    /**
     * The events a client may report, and only a client: claims the signer's
     * browser makes (a page shown, a rendering of the data), never facts.
     */
    public const CLIENT_TYPES = ['page.viewed', 'document.rendered'];

    /**
     * @param  array{seq: int, envelope_id: string, at: string, type: string, actor: string, source: string, ip: ?string, user_agent: ?string, data: array<string, mixed>}  $e
     */
    public static function hashOf(string $prev, array $e): string
    {
        return hash('sha256', $prev."\n".CanonicalJson::encode([
            'seq' => $e['seq'],
            'envelope_id' => $e['envelope_id'],
            'at' => $e['at'],
            'type' => $e['type'],
            'actor' => $e['actor'],
            'source' => $e['source'],
            'ip' => $e['ip'],
            'user_agent' => $e['user_agent'],
            'data' => $e['data'] === [] ? new stdClass : $e['data'],
        ]));
    }

    /**
     * A list of events from JSON (an exported certificate, a verify upload),
     * each row an array whose `data` keeps JSON objects as objects, so an
     * object with keys "0", "1", ... is never read back as a list. Null when
     * the JSON is not a list of objects.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function rowsFromJson(string $json): ?array
    {
        $decoded = json_decode($json, false, 64);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }
        $rows = [];
        foreach ($decoded as $row) {
            if (! $row instanceof stdClass) {
                return null;
            }
            $rows[] = get_object_vars($row);
        }

        return $rows;
    }

    /**
     * The next event after `$last` (null for the first), with seq, prev_hash and hash filled in.
     *
     * @param  array<string, mixed>|null  $last
     * @param  array{envelope_id: string, at: string, type: string, actor: string, source: string, ip: ?string, user_agent: ?string, data: array<string, mixed>}  $e
     * @return array<string, mixed>
     */
    public static function append(?array $last, array $e): array
    {
        if (! in_array($e['type'], self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown event type {$e['type']}.");
        }
        if (! in_array($e['source'], ['server', 'client'], true) || ($e['source'] === 'client') !== in_array($e['type'], self::CLIENT_TYPES, true)) {
            throw new InvalidArgumentException("Event {$e['type']} cannot have source {$e['source']}.");
        }
        $e['seq'] = $last === null || $last === [] ? 1 : (int) $last['seq'] + 1;
        $e['prev_hash'] = $last === null || $last === [] ? self::GENESIS : (string) $last['hash'];
        $e['hash'] = self::hashOf($e['prev_hash'], $e);

        return [
            'envelope_id' => $e['envelope_id'], 'seq' => $e['seq'], 'at' => $e['at'], 'type' => $e['type'], 'actor' => $e['actor'],
            'source' => $e['source'], 'ip' => $e['ip'], 'user_agent' => $e['user_agent'], 'data' => $e['data'],
            'prev_hash' => $e['prev_hash'], 'hash' => $e['hash'],
        ];
    }

    /**
     * Check a chain read as raw rows (a value the store would reject still
     * reaches the check). `data` may be an array or a JSON string.
     *
     * @param  list<array<string, mixed>>  $rows  in seq order
     * @return array{ok: bool, checked: int, head: string, seq?: int, problem?: string}
     */
    public static function verify(array $rows): array
    {
        $prev = self::GENESIS;
        $expected = 1;
        $checked = 0;
        $envelope = null;
        foreach ($rows as $row) {
            $seq = (int) ($row['seq'] ?? 0);
            if ($seq !== $expected) {
                return ['ok' => false, 'checked' => $checked, 'head' => $prev, 'seq' => $seq, 'problem' => 'sequence_gap'];
            }
            if (($row['prev_hash'] ?? null) !== $prev) {
                return ['ok' => false, 'checked' => $checked, 'head' => $prev, 'seq' => $seq, 'problem' => 'broken_link'];
            }
            $envelope ??= $row['envelope_id'] ?? null;
            // Decoded with objects kept as objects: {"0": "x"} must hash as an object, never as a list.
            $data = is_string($row['data'] ?? null) ? json_decode($row['data'], false) : ($row['data'] ?? null);
            $ok = (is_array($data) || $data instanceof stdClass) && ($row['envelope_id'] ?? null) === $envelope;
            try {
                $ok = $ok && self::hashOf($prev, [
                    'seq' => $seq, 'envelope_id' => (string) $row['envelope_id'], 'at' => (string) ($row['at'] ?? ''),
                    'type' => (string) ($row['type'] ?? ''), 'actor' => (string) ($row['actor'] ?? ''), 'source' => (string) ($row['source'] ?? ''),
                    'ip' => $row['ip'] ?? null, 'user_agent' => $row['user_agent'] ?? null, 'data' => $data,
                ]) === ($row['hash'] ?? null);
            } catch (\Throwable) {
                $ok = false;
            }
            if (! $ok) {
                return ['ok' => false, 'checked' => $checked, 'head' => $prev, 'seq' => $seq, 'problem' => 'hash_mismatch'];
            }
            $prev = (string) $row['hash'];
            $expected++;
            $checked++;
        }

        return ['ok' => true, 'checked' => $checked, 'head' => $prev];
    }
}
