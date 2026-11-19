<?php

declare(strict_types=1);

namespace FancySign\Tests\Support;

use FancySign\Repository\InMemoryRepository;
use FancySign\Repository\Repository;

/**
 * An in-memory store whose envelope reads can be pinned to a STALE copy: what
 * a second request sees when it read the envelope before a first request
 * wrote. Everything else, the chain included, is live. Used to prove a check
 * is made from the chain under the append lock, not from an earlier read.
 */
final class StaleEnvelopeRepository implements Repository
{
    public readonly InMemoryRepository $inner;

    /** @var array<string, mixed>|null */
    public ?array $stale = null;

    public function __construct()
    {
        $this->inner = new InMemoryRepository();
    }

    public function saveEnvelope(array $envelope): void
    {
        $this->inner->saveEnvelope($envelope);
    }

    public function envelope(string $id): ?array
    {
        return $this->stale ?? $this->inner->envelope($id);
    }

    public function envelopes(?string $orgId = null): array
    {
        return $this->inner->envelopes($orgId);
    }

    public function appendEvent(string $envelopeId, callable $build): array
    {
        return $this->inner->appendEvent($envelopeId, $build);
    }

    public function events(string $envelopeId): array
    {
        return $this->inner->events($envelopeId);
    }

    public function putLink(string $tokenHash, string $envelopeId, string $signerId): void
    {
        $this->inner->putLink($tokenHash, $envelopeId, $signerId);
    }

    public function link(string $tokenHash): ?array
    {
        return $this->inner->link($tokenHash);
    }

    public function deleteLinks(string $envelopeId, string $signerId): void
    {
        $this->inner->deleteLinks($envelopeId, $signerId);
    }

    public function putOtp(string $key, array $otp): void
    {
        $this->inner->putOtp($key, $otp);
    }

    public function pullOtp(string $key): ?array
    {
        return $this->inner->pullOtp($key);
    }

    public function putSession(string $hash, array $session): void
    {
        $this->inner->putSession($hash, $session);
    }

    public function session(string $hash): ?array
    {
        return $this->inner->session($hash);
    }

    public function hit(string $key, int $windowSeconds, int $now): int
    {
        return $this->inner->hit($key, $windowSeconds, $now);
    }

    public function putBlob(string $key, string $bytes): void
    {
        $this->inner->putBlob($key, $bytes);
    }

    public function blob(string $key): ?string
    {
        return $this->inner->blob($key);
    }

    public function orgSettings(string $orgId): ?array
    {
        return $this->inner->orgSettings($orgId);
    }

    public function saveOrgSettings(array $settings): void
    {
        $this->inner->saveOrgSettings($settings);
    }
}
