<?php

declare(strict_types=1);

use FancySign\Events\EnvelopeEventRecorded;
use FancySign\Laravel\DeliverEnvelopeEvent;
use FancySign\Listener\SignListener;
use FancySign\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;

/*
 * The host hook through the Laravel bridge: every committed event becomes an
 * EnvelopeEventRecorded Laravel event and a call to a bound SignListener,
 * delivered by a queued job (DeliverEnvelopeEvent) dispatched after the
 * database commit, retried on failure, and never able to fail the request.
 */

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

/** Run the whole HTTP ceremony; returns the envelope id. */
function fsHttpCeremony(): string
{
    $id = fsHttp('POST', '/envelopes', fsHttpEnvelope())['body']['id'];
    fsHttp('POST', "/envelopes/{$id}/send");
    $token = TestApp::$mailer->token('alice@example.com');
    fsHttp('POST', "/s/{$token}/otp", ['purpose' => 'view']);
    $session = fsHttp('POST', "/s/{$token}/otp/verify", ['purpose' => 'view', 'code' => TestApp::$mailer->code('alice@example.com', 'view')])['body']['session'];
    $h = ['X-Fancy-Sign-Session' => $session];
    fsHttp('POST', "/s/{$token}/consent", ['version' => '1'], headers: $h);
    fsHttp('GET', "/s/{$token}/document", headers: $h);
    $fields = [];
    foreach (fsHttp('GET', "/s/{$token}/envelope", headers: $h)['body']['fields'] as $f) {
        $fields[$f['id']] = true;
    }
    fsHttp('POST', "/s/{$token}/otp", ['purpose' => 'accept'], headers: $h);
    $r = fsHttp('POST', "/s/{$token}/sign", ['fields' => $fields, 'mark' => ['kind' => 'typed', 'text' => 'Alice Example'],
        'adoption' => 'I adopt this as my signature, and I intend to sign this document.', 'code' => TestApp::$mailer->code('alice@example.com', 'accept')], headers: $h);
    expect($r['status'])->toBe(200);

    return $id;
}

it('dispatches EnvelopeEventRecorded once per committed event, with the seq to dedupe on', function () {
    TestApp::boot();
    $seen = [];
    Event::listen(EnvelopeEventRecorded::class, function (EnvelopeEventRecorded $e) use (&$seen): void {
        $seen[] = $e;
    });

    $id = fsHttpCeremony();
    $chain = DB::table('fancy_sign_events')->where('envelope_id', $id)->orderBy('seq')->get();

    expect(array_map(fn ($e) => $e->seq, $seen))->toBe($chain->pluck('seq')->map(fn ($s) => (int) $s)->all())
        ->and(array_map(fn ($e) => $e->eventType, $seen))->toBe($chain->pluck('type')->all())
        ->and(array_unique(array_map(fn ($e) => $e->dedupeKey(), $seen)))->toHaveCount(count($seen))
        ->and(array_map(fn ($e) => $e->eventType, $seen))->toContain('document.opened', 'signer.signed', 'envelope.completed');
});

it('calls the SignListener the host binds', function () {
    TestApp::boot();
    $listener = new class implements SignListener
    {
        /** @var list<string> */
        public array $types = [];

        public function recorded(EnvelopeEventRecorded $event): void
        {
            $this->types[] = $event->eventType;
        }
    };
    app()->instance(SignListener::class, $listener);

    fsHttpCeremony();

    expect($listener->types)->toContain('envelope.created', 'signer.signed', 'envelope.completed');
});

it('never fails or rolls back the signer\'s request when the host listener throws', function () {
    TestApp::boot();
    app()->instance(SignListener::class, new class implements SignListener
    {
        public function recorded(EnvelopeEventRecorded $event): void
        {
            throw new RuntimeException('the host CRM is down');
        }
    });
    Event::listen(EnvelopeEventRecorded::class, function (): void {
        throw new RuntimeException('a Laravel listener failed too');
    });

    $id = fsHttpCeremony();
    $types = DB::table('fancy_sign_events')->where('envelope_id', $id)->orderBy('seq')->pluck('type')->all();

    expect($types)->toContain('signer.signed', 'envelope.completed', 'seal.applied')
        ->and(fsHttp('GET', "/envelopes/{$id}")['body']['status'])->toBe('completed');
});

it('delivers nothing for a transaction the host rolls back', function () {
    TestApp::boot();
    $seen = 0;
    Event::listen(EnvelopeEventRecorded::class, function () use (&$seen): void {
        $seen++;
    });

    DB::beginTransaction();
    app(FancySign\Signing::class)->create(fsHttpEnvelope(), 'user:1');
    DB::rollBack();

    expect($seen)->toBe(0);

    DB::transaction(fn () => app(FancySign\Signing::class)->create(fsHttpEnvelope(), 'user:1'));
    expect($seen)->toBe(1);
});

it('queues nothing while the host listens for nothing', function () {
    TestApp::boot();
    Illuminate\Support\Facades\Bus::fake();

    fsHttp('POST', '/envelopes', fsHttpEnvelope());
    Illuminate\Support\Facades\Bus::assertNotDispatched(DeliverEnvelopeEvent::class);

    Event::listen(EnvelopeEventRecorded::class, fn () => null);
    fsHttp('POST', '/envelopes', fsHttpEnvelope());
    Illuminate\Support\Facades\Bus::assertDispatched(DeliverEnvelopeEvent::class, fn (DeliverEnvelopeEvent $job) => $job->event->eventType === 'envelope.created');
});

it('is a queued job with retries, which rethrows a listener failure so the queue retries it', function () {
    TestApp::boot();
    $job = new DeliverEnvelopeEvent(new EnvelopeEventRecorded('E1', 'signer.signed', 7, '2026-11-18T12:00:00Z', 'signer:alice', 'server'));
    expect($job)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class)
        ->and($job->tries)->toBeGreaterThan(1)
        ->and($job->backoff())->not->toBe([]);

    app()->instance(SignListener::class, new class implements SignListener
    {
        public function recorded(EnvelopeEventRecorded $event): void
        {
            throw new RuntimeException('down');
        }
    });

    expect(fn () => $job->handle(app('events'), app()))->toThrow(RuntimeException::class);
});
