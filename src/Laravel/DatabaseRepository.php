<?php

declare(strict_types=1);

namespace FancySign\Laravel;

use FancySign\Repository\Repository;
use Illuminate\Database\ConnectionInterface;

/**
 * The Repository on Laravel's query builder (any driver). Envelopes are stored
 * as one JSON document per row; events are rows, one chain per envelope, with
 * a unique (envelope_id, seq) so two writers can never both claim a seq.
 * Blobs (the PDFs) are base64 in a text column, so every driver stores them.
 */
final class DatabaseRepository implements Repository
{
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(private readonly ConnectionInterface $db) {}

    public function saveEnvelope(array $envelope): void
    {
        $this->db->table('fancy_sign_envelopes')->updateOrInsert(['id' => $envelope['id']], [
            'org_id' => $envelope['org_id'], 'status' => $envelope['status'], 'created_by' => $envelope['created_by'],
            'data' => json_encode($envelope, self::JSON), 'created_at' => $envelope['created_at'],
        ]);
    }

    public function envelope(string $id): ?array
    {
        $data = $this->db->table('fancy_sign_envelopes')->where('id', $id)->value('data');

        return is_string($data) ? json_decode($data, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function envelopes(?string $orgId = null): array
    {
        $q = $this->db->table('fancy_sign_envelopes')->orderBy('created_at')->orderBy('id');
        if ($orgId !== null) {
            $q->where('org_id', $orgId);
        }

        return array_map(fn ($row) => json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR), $q->get(['data'])->all());
    }

    public function appendEvent(string $envelopeId, callable $build): array
    {
        return $this->db->transaction(function () use ($envelopeId, $build): array {
            $this->db->table('fancy_sign_envelopes')->where('id', $envelopeId)->lockForUpdate()->value('id');
            $last = $this->db->table('fancy_sign_events')->where('envelope_id', $envelopeId)->orderByDesc('seq')->first();
            $event = $build($last === null ? null : $this->row($last));
            $this->db->table('fancy_sign_events')->insert(['data' => json_encode($event['data'] === [] ? new \stdClass : $event['data'], self::JSON)] + $event);

            return $event;
        });
    }

    public function events(string $envelopeId): array
    {
        return array_map(fn ($r) => $this->row($r), $this->db->table('fancy_sign_events')->where('envelope_id', $envelopeId)->orderBy('seq')->get()->all());
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        return [
            'envelope_id' => (string) $r->envelope_id, 'seq' => (int) $r->seq, 'at' => (string) $r->at, 'type' => (string) $r->type,
            'actor' => (string) $r->actor, 'source' => (string) $r->source, 'ip' => $r->ip, 'user_agent' => $r->user_agent,
            'data' => json_decode((string) $r->data, true, 512, JSON_THROW_ON_ERROR), 'prev_hash' => (string) $r->prev_hash, 'hash' => (string) $r->hash,
        ];
    }

    public function putLink(string $tokenHash, string $envelopeId, string $signerId): void
    {
        $this->db->table('fancy_sign_links')->insert(['token_hash' => $tokenHash, 'envelope_id' => $envelopeId, 'signer_id' => $signerId]);
    }

    public function link(string $tokenHash): ?array
    {
        $r = $this->db->table('fancy_sign_links')->where('token_hash', $tokenHash)->first();

        return $r === null ? null : ['envelope_id' => (string) $r->envelope_id, 'signer_id' => (string) $r->signer_id];
    }

    public function deleteLinks(string $envelopeId, string $signerId): void
    {
        $this->db->table('fancy_sign_links')->where('envelope_id', $envelopeId)->where('signer_id', $signerId)->delete();
    }

    public function putOtp(string $key, array $otp): void
    {
        $this->db->table('fancy_sign_otps')->updateOrInsert(['key' => $key], $otp);
    }

    /** Read, then delete the exact row read: a racing caller deletes nothing and gets null. */
    public function pullOtp(string $key): ?array
    {
        $r = $this->db->table('fancy_sign_otps')->where('key', $key)->first();
        if ($r === null) {
            return null;
        }
        $gone = $this->db->table('fancy_sign_otps')->where('key', $key)->where('hash', $r->hash)->where('attempts', $r->attempts)->delete();

        return $gone === 1 ? ['hash' => (string) $r->hash, 'expires_at' => (int) $r->expires_at, 'attempts' => (int) $r->attempts] : null;
    }

    public function putSession(string $hash, array $session): void
    {
        $this->db->table('fancy_sign_sessions')->insert(['hash' => $hash] + $session);
    }

    public function session(string $hash): ?array
    {
        $r = $this->db->table('fancy_sign_sessions')->where('hash', $hash)->first();

        return $r === null ? null : ['envelope_id' => (string) $r->envelope_id, 'signer_id' => (string) $r->signer_id, 'expires_at' => (int) $r->expires_at];
    }

    public function hit(string $key, int $windowSeconds, int $now): int
    {
        return $this->db->transaction(function () use ($key, $windowSeconds, $now): int {
            $r = $this->db->table('fancy_sign_hits')->where('key', $key)->lockForUpdate()->first();
            $count = $r === null || (int) $r->ends <= $now ? 1 : (int) $r->count + 1;
            $ends = $r === null || (int) $r->ends <= $now ? $now + $windowSeconds : (int) $r->ends;
            $this->db->table('fancy_sign_hits')->updateOrInsert(['key' => $key], ['count' => $count, 'ends' => $ends]);

            return $count;
        });
    }

    public function putBlob(string $key, string $bytes): void
    {
        $this->db->table('fancy_sign_blobs')->updateOrInsert(['key' => $key], ['bytes' => base64_encode($bytes)]);
    }

    public function blob(string $key): ?string
    {
        $b = $this->db->table('fancy_sign_blobs')->where('key', $key)->value('bytes');

        return is_string($b) ? (string) base64_decode($b, true) : null;
    }
}
