<?php

declare(strict_types=1);

namespace FancySign\Repository;

/**
 * Storage. Two implementations ship: {@see InMemoryRepository} (tests, scripts)
 * and the Laravel bridge's DatabaseRepository. Records are plain arrays.
 *
 * Secrets are stored hashed only: link tokens, session tokens and codes.
 */
interface Repository
{
    /** @param array<string, mixed> $envelope */
    public function saveEnvelope(array $envelope): void;

    /** @return array<string, mixed>|null */
    public function envelope(string $id): ?array;

    /** @return list<array<string, mixed>> oldest first */
    public function envelopes(?string $orgId = null): array;

    /**
     * Append an event to an envelope's chain. `$build` receives the last event
     * (or null) and returns the new one; the store runs it under a lock so two
     * writers can never both claim the same seq.
     *
     * @param  callable(?array): array<string, mixed>  $build
     * @return array<string, mixed> the stored event
     */
    public function appendEvent(string $envelopeId, callable $build): array;

    /** @return list<array<string, mixed>> in seq order */
    public function events(string $envelopeId): array;

    public function putLink(string $tokenHash, string $envelopeId, string $signerId): void;

    /** @return array{envelope_id: string, signer_id: string}|null */
    public function link(string $tokenHash): ?array;

    public function deleteLinks(string $envelopeId, string $signerId): void;

    /** @param array{hash: string, expires_at: int, attempts: int} $otp */
    public function putOtp(string $key, array $otp): void;

    /**
     * Take the code out of the store in one operation (consumed by pull): a
     * second caller racing the first gets null.
     *
     * @return array{hash: string, expires_at: int, attempts: int}|null
     */
    public function pullOtp(string $key): ?array;

    /** @param array{envelope_id: string, signer_id: string, expires_at: int} $session */
    public function putSession(string $hash, array $session): void;

    /** @return array{envelope_id: string, signer_id: string, expires_at: int}|null */
    public function session(string $hash): ?array;

    /** Count a hit in a fixed window and return the count, this one included. */
    public function hit(string $key, int $windowSeconds, int $now): int;

    public function putBlob(string $key, string $bytes): void;

    public function blob(string $key): ?string;
}
