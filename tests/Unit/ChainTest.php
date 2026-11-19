<?php

declare(strict_types=1);

use FancySign\Chain\EventChain;

/*
 * change-council's chain, one per envelope:
 *   hash = sha256(prev_hash + "\n" + canonical({seq, envelope_id, at, type, actor, source, ip, user_agent, data}))
 * with a genesis of 64 zeros. Pinned by a vector, so a port reproduces it.
 */

it('pins the hash of a first event (a vector a Node port must reproduce)', function () {
    $hash = EventChain::hashOf(EventChain::GENESIS, [
        'seq' => 1, 'envelope_id' => '01JD3XAMPLE', 'at' => '2026-11-13T12:00:00Z', 'type' => 'envelope.created',
        'actor' => 'user:1', 'source' => 'server', 'ip' => null, 'user_agent' => null, 'data' => ['title' => 'Quote'],
    ]);

    $canonical = '{"actor":"user:1","at":"2026-11-13T12:00:00Z","data":{"title":"Quote"},"envelope_id":"01JD3XAMPLE","ip":null,"seq":1,"source":"server","type":"envelope.created","user_agent":null}';
    expect($hash)->toBe(hash('sha256', EventChain::GENESIS."\n".$canonical));
});

it('refuses an event type outside the closed set', function () {
    expect(fn () => EventChain::append([], ['envelope_id' => 'E', 'at' => 'x', 'type' => 'signer.forged', 'actor' => 'a', 'source' => 'server', 'ip' => null, 'user_agent' => null, 'data' => []]))
        ->toThrow(InvalidArgumentException::class);
});

it('verifies an empty chain as ok with the genesis head', function () {
    expect(EventChain::verify([]))->toBe(['ok' => true, 'checked' => 0, 'head' => EventChain::GENESIS]);
});

// CC-fancy-0052 (data mode): two events join the closed set.

function fsChainEvent(string $type, string $source): array
{
    return ['envelope_id' => '01JD3XAMPLE', 'at' => '2026-11-19T12:00:00Z', 'type' => $type, 'actor' => 'signer:alice',
        'source' => $source, 'ip' => null, 'user_agent' => null, 'data' => ['x' => 1]];
}

it('admits document.rendered from the client only: a browser claim, like page.viewed', function () {
    expect(EventChain::append(null, fsChainEvent('document.rendered', 'client'))['source'])->toBe('client')
        ->and(fn () => EventChain::append(null, fsChainEvent('document.rendered', 'server')))->toThrow(InvalidArgumentException::class);
});

it('admits signature.sealed from the server only', function () {
    expect(EventChain::append(null, fsChainEvent('signature.sealed', 'server'))['type'])->toBe('signature.sealed')
        ->and(fn () => EventChain::append(null, fsChainEvent('signature.sealed', 'client')))->toThrow(InvalidArgumentException::class);
});

it('admits document.finalized from the server only (attachFinal)', function () {
    expect(EventChain::append(null, fsChainEvent('document.finalized', 'server'))['type'])->toBe('document.finalized')
        ->and(fn () => EventChain::append(null, fsChainEvent('document.finalized', 'client')))->toThrow(InvalidArgumentException::class);
});
