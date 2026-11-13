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
