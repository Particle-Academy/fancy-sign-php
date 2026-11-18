<?php

declare(strict_types=1);

namespace FancySign\Events;

/**
 * One event, committed to an envelope's chain. The host hook carries this:
 * to a bound {@see \FancySign\Listener\SignListener}, and, through the Laravel
 * bridge, as a Laravel event of this class.
 *
 * Plain values only, so a queue can serialise it. Delivery is AT LEAST once:
 * dedupe on {@see dedupeKey()} (the envelope id and the seq, which is unique
 * per envelope and never reused).
 *
 * It carries no event `data`. A host that needs it reads the envelope's
 * events through the package; a code, a token or a signer's typed text never
 * leaves the chain this way.
 */
final class EnvelopeEventRecorded
{
    public function __construct(
        public readonly string $envelopeId,
        public readonly string $eventType,
        public readonly int $seq,
        public readonly string $at,
        public readonly string $actor,
        public readonly string $source,
    ) {}

    /** @param array<string, mixed> $event a stored chain event */
    public static function fromChainEvent(array $event): self
    {
        return new self((string) $event['envelope_id'], (string) $event['type'], (int) $event['seq'], (string) $event['at'],
            (string) $event['actor'], (string) $event['source']);
    }

    /** `<envelope id>:<seq>`: the same event delivered twice has the same key. */
    public function dedupeKey(): string
    {
        return $this->envelopeId.':'.$this->seq;
    }

    /** @return array{envelope_id: string, event_type: string, seq: int, at: string, actor: string, source: string} */
    public function toArray(): array
    {
        return ['envelope_id' => $this->envelopeId, 'event_type' => $this->eventType, 'seq' => $this->seq,
            'at' => $this->at, 'actor' => $this->actor, 'source' => $this->source];
    }
}
