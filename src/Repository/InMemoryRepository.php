<?php

declare(strict_types=1);

namespace FancySign\Repository;

/** A Repository in PHP arrays: for tests and short scripts, never for production. */
final class InMemoryRepository implements Repository
{
    /** @var array<string, array<string, mixed>> */
    private array $envelopes = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $events = [];

    /** @var array<string, array{envelope_id: string, signer_id: string}> */
    private array $links = [];

    /** @var array<string, array{hash: string, expires_at: int, attempts: int}> */
    private array $otps = [];

    /** @var array<string, array{envelope_id: string, signer_id: string, expires_at: int}> */
    private array $sessions = [];

    /** @var array<string, array{count: int, ends: int}> */
    private array $hits = [];

    /** @var array<string, string> */
    private array $blobs = [];

    public function saveEnvelope(array $envelope): void
    {
        $this->envelopes[$envelope['id']] = $envelope;
    }

    public function envelope(string $id): ?array
    {
        return $this->envelopes[$id] ?? null;
    }

    public function envelopes(?string $orgId = null): array
    {
        return array_values(array_filter($this->envelopes, fn (array $e) => $orgId === null || $e['org_id'] === $orgId));
    }

    public function appendEvent(string $envelopeId, callable $build): array
    {
        $list = $this->events[$envelopeId] ?? [];
        $event = $build($list === [] ? null : $list[count($list) - 1]);
        $this->events[$envelopeId][] = $event;

        return $event;
    }

    public function events(string $envelopeId): array
    {
        return $this->events[$envelopeId] ?? [];
    }

    public function putLink(string $tokenHash, string $envelopeId, string $signerId): void
    {
        $this->links[$tokenHash] = ['envelope_id' => $envelopeId, 'signer_id' => $signerId];
    }

    public function link(string $tokenHash): ?array
    {
        return $this->links[$tokenHash] ?? null;
    }

    public function deleteLinks(string $envelopeId, string $signerId): void
    {
        $this->links = array_filter($this->links, fn (array $l) => ! ($l['envelope_id'] === $envelopeId && $l['signer_id'] === $signerId));
    }

    public function putOtp(string $key, array $otp): void
    {
        $this->otps[$key] = $otp;
    }

    public function pullOtp(string $key): ?array
    {
        $otp = $this->otps[$key] ?? null;
        unset($this->otps[$key]);

        return $otp;
    }

    public function putSession(string $hash, array $session): void
    {
        $this->sessions[$hash] = $session;
    }

    public function session(string $hash): ?array
    {
        return $this->sessions[$hash] ?? null;
    }

    public function hit(string $key, int $windowSeconds, int $now): int
    {
        $h = $this->hits[$key] ?? null;
        if ($h === null || $h['ends'] <= $now) {
            $h = ['count' => 0, 'ends' => $now + $windowSeconds];
        }
        $h['count']++;
        $this->hits[$key] = $h;

        return $h['count'];
    }

    public function putBlob(string $key, string $bytes): void
    {
        $this->blobs[$key] = $bytes;
    }

    public function blob(string $key): ?string
    {
        return $this->blobs[$key] ?? null;
    }
}
