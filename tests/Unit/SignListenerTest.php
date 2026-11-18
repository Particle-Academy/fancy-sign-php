<?php

declare(strict_types=1);

use FancySign\Chain\EventChain;
use FancySign\Config;
use FancySign\Events\EnvelopeEventRecorded;
use FancySign\Listener\SignListener;
use FancySign\Repository\InMemoryRepository;
use FancySign\Signing;
use FancySign\Tests\Support\FakeMailer;
use FancySign\Tests\Support\FrozenClock;

/*
 * The host hook (0.2.0, owner-approved q_c2b20c9e): after each event is
 * committed to an envelope's chain, the host is told the envelope id, the
 * event type, its seq and its time. Every event in the closed set, in seq
 * order, each exactly once. A listener's failure never fails, and never
 * rolls back, the request that recorded the event.
 */

/** @return array{signing: Signing, mailer: FakeMailer, clock: FrozenClock, repo: InMemoryRepository} */
function fsHooked(SignListener $listener): array
{
    $mailer = new FakeMailer();
    $clock = new FrozenClock();
    $repo = new InMemoryRepository();
    $signing = new Signing(
        repo: $repo,
        mailer: $mailer,
        sealSecretKey: fsKeys()['secret'],
        appKey: str_repeat('k', 32),
        linkUrl: fn (string $token): string => 'https://app.example.test/sign/'.$token,
        config: new Config(),
        clock: $clock,
        listener: $listener,
    );

    return ['signing' => $signing, 'mailer' => $mailer, 'clock' => $clock, 'repo' => $repo];
}

final class RecordingSignListener implements SignListener
{
    /** @var list<EnvelopeEventRecorded> */
    public array $seen = [];

    public function recorded(EnvelopeEventRecorded $event): void
    {
        $this->seen[] = $event;
    }
}

final class ThrowingSignListener implements SignListener
{
    public int $calls = 0;

    public function recorded(EnvelopeEventRecorded $event): void
    {
        $this->calls++;
        throw new RuntimeException('the host CRM is down');
    }
}

it('tells the host about every committed event, once, in seq order, with the envelope id, type, seq and time', function () {
    $listener = new RecordingSignListener();
    $h = fsHooked($listener);
    $env = fsSent($h);
    [$token, $session] = fsViewing($h);
    fsSign($h, $token, $session);

    $chain = $h['repo']->events($env['id']);
    expect($listener->seen)->toHaveCount(count($chain));
    foreach ($chain as $i => $e) {
        $told = $listener->seen[$i];
        expect($told->envelopeId)->toBe($env['id'])
            ->and($told->eventType)->toBe($e['type'])
            ->and($told->seq)->toBe($e['seq'])
            ->and($told->at)->toBe($e['at'])
            ->and($told->source)->toBe($e['source'])
            ->and($told->actor)->toBe($e['actor']);
    }
    $types = array_map(fn (EnvelopeEventRecorded $e) => $e->eventType, $listener->seen);
    expect($types)->toContain('document.opened', 'signer.signed', 'envelope.completed', 'seal.applied');
});

it('tells the host about a decline and a void', function () {
    $listener = new RecordingSignListener();
    $h = fsHooked($listener);
    $env = fsSent($h, bob: true);
    [$token, $session] = fsViewing($h);
    $h['signing']->decline($token, $session, 'Price too high.', fsCtx());
    $voided = fsHooked($listener);
    $other = fsSent($voided);
    $voided['signing']->void($other['id'], 'Sent by mistake.', 'user:1');

    $types = array_map(fn (EnvelopeEventRecorded $e) => $e->eventType, $listener->seen);
    expect($types)->toContain('signer.declined', 'envelope.voided');
});

it('never fails the signer\'s request when the listener throws, and the event is still chained', function () {
    $listener = new ThrowingSignListener();
    $h = fsHooked($listener);
    $env = fsSent($h);
    [$token, $session] = fsViewing($h);
    $view = fsSign($h, $token, $session);

    $chain = $h['repo']->events($env['id']);
    expect($view['signer']['status'])->toBe('signed')
        ->and(array_column($chain, 'type'))->toContain('signer.signed', 'envelope.completed')
        ->and(EventChain::verify($chain)['ok'])->toBeTrue()
        ->and($listener->calls)->toBe(count($chain));
});

it('carries only plain values, so a queue can serialise it', function () {
    $e = new EnvelopeEventRecorded('01JD0000000000000000000000', 'signer.signed', 7, '2026-11-18T12:00:00Z', 'signer:alice', 'server');

    expect(unserialize(serialize($e)))->toEqual($e)
        ->and($e->toArray())->toBe(['envelope_id' => '01JD0000000000000000000000', 'event_type' => 'signer.signed', 'seq' => 7,
            'at' => '2026-11-18T12:00:00Z', 'actor' => 'signer:alice', 'source' => 'server'])
        ->and($e->dedupeKey())->toBe('01JD0000000000000000000000:7');
});
