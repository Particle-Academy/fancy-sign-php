<?php

declare(strict_types=1);

use FancySign\Events\EnvelopeEventRecorded;
use FancySign\Signing;
use FancySign\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;

/*
 * Signing::preview under the Laravel bridge: the database store, the host's
 * own mailer (LaravelMailer) and the queued host hook. A preview writes no row
 * to any fancy-sign table, dispatches no EnvelopeEventRecorded and sends no
 * mail. Each instrument is proved live afterwards by a real send through the
 * same wiring.
 *
 * Mail is read from the `array` transport rather than Mail::fake(): the bridge
 * sends raw mail, and MailFake::raw() records nothing, so a fake would report
 * "nothing sent" whatever happened. Events are recorded by a spy listener
 * rather than Event::fake(), which needs the cache service this hand-built
 * kernel does not boot (as HostHookTest does).
 */

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

/** Row count of every fancy-sign table. */
function fsPvTableCounts(): array
{
    $out = [];
    foreach (['envelopes', 'events', 'links', 'otps', 'sessions', 'hits', 'org_settings', 'blobs'] as $t) {
        $out[$t] = DB::table("fancy_sign_{$t}")->count();
    }

    return $out;
}

/** Messages the host's mailer has handed to the array transport. */
function fsPvMailCount(): int
{
    return count(app('mailer')->getSymfonyTransport()->messages());
}

it('writes no row, dispatches no event and sends no mail through the Laravel bridge', function () {
    TestApp::boot(fakeMailer: false);
    $dispatched = [];
    Event::listen(EnvelopeEventRecorded::class, function (EnvelopeEventRecorded $e) use (&$dispatched): void {
        $dispatched[] = $e->eventType;   // a host listening, so the hook would deliver
    });
    $signing = app(Signing::class);
    $signing->setOrgSettings('org-1', ['per_page' => 'initials'], 'user:admin');
    $before = fsPvTableCounts();

    $pdf = $signing->preview(fsHttpEnvelope(), 'org-1');

    expect($pdf)->toStartWith('%PDF-')
        ->and(fsPvTableCounts())->toBe($before)
        ->and($before['envelopes'])->toBe(0)
        ->and(fsPvMailCount())->toBe(0);
    expect($dispatched)->toBe([]);

    // The instruments are live: a real send through the same wiring shows up in all three.
    $signing->send($signing->create(fsHttpEnvelope(), 'user:1', 'org-1')['id'], 'user:1');
    $after = fsPvTableCounts();
    expect($after['envelopes'])->toBe(1)
        ->and($after['events'])->toBeGreaterThan(0)
        ->and($after['blobs'])->toBe(1)
        ->and(fsPvMailCount())->toBe(1);
    expect($dispatched)->toContain('envelope.sent');
});

it('previewDocument writes no row, dispatches no event and sends no mail through the Laravel bridge', function () {
    TestApp::boot(fakeMailer: false);
    $dispatched = [];
    Event::listen(EnvelopeEventRecorded::class, function (EnvelopeEventRecorded $e) use (&$dispatched): void {
        $dispatched[] = $e->eventType;
    });
    $signing = app(Signing::class);
    $signing->setOrgSettings('org-1', ['per_page' => 'initials', 'final_page' => 'auto'], 'user:admin');
    $before = fsPvTableCounts();

    $model = $signing->previewDocument(fsHttpEnvelope(), 'org-1');

    expect($model['signature_section']['placement'])->toBe('after_content')
        ->and(fsPvTableCounts())->toBe($before)
        ->and($before['envelopes'])->toBe(0)
        ->and(fsPvMailCount())->toBe(0);
    expect($dispatched)->toBe([]);

    // The org's final_page reached the database store and back.
    expect($signing->orgSettings('org-1')['final_page'])->toBe('auto');

    // The instruments are live.
    $signing->send($signing->create(fsHttpEnvelope(), 'user:1', 'org-1')['id'], 'user:1');
    expect(fsPvTableCounts()['envelopes'])->toBe(1)
        ->and(fsPvMailCount())->toBe(1);
    expect($dispatched)->toContain('envelope.sent');
});
