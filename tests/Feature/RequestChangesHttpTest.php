<?php

declare(strict_types=1);

use FancySign\Events\EnvelopeEventRecorded;
use FancySign\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;

/*
 * POST {prefix}/s/{token}/request-changes {text}: chained, delivered through
 * the host hook, readable from the events endpoint, and refused over the cap
 * with the kit-wide error envelope.
 */

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

it('records a request over HTTP, tells the host, keeps the envelope open, and refuses over the cap', function () {
    TestApp::boot(['fancy-sign.limits' => ['max_change_requests' => 1]]);
    $told = [];
    Event::listen(EnvelopeEventRecorded::class, function (EnvelopeEventRecorded $e) use (&$told): void {
        $told[] = $e->eventType;
    });

    $id = fsHttp('POST', '/envelopes', fsHttpEnvelope())['body']['id'];
    fsHttp('POST', "/envelopes/{$id}/send");
    $token = TestApp::$mailer->token('alice@example.com');

    // No session yet: the view code comes first.
    expect(fsHttp('POST', "/s/{$token}/request-changes", ['text' => 'Change it.'])['code'])->toBe('session_required');

    fsHttp('POST', "/s/{$token}/otp", ['purpose' => 'view']);
    $session = fsHttp('POST', "/s/{$token}/otp/verify", ['purpose' => 'view', 'code' => TestApp::$mailer->code('alice@example.com', 'view')])['body']['session'];
    $h = ['X-Fancy-Sign-Session' => $session];

    $r = fsHttp('POST', "/s/{$token}/request-changes", ['text' => 'Please itemise the delivery.'], headers: $h);
    expect($r['status'])->toBe(200)->and($r['body']['changes_requested'])->toBe(1)->and($r['body']['envelope']['status'])->toBe('sent');

    $over = fsHttp('POST', "/s/{$token}/request-changes", ['text' => 'Again.'], headers: $h);
    expect($over['status'])->toBe(429)
        ->and($over['body']['error'])->toBe('limit_exceeded')
        ->and($over['body']['requestId'])->toBeString();

    expect(fsHttp('POST', "/s/{$token}/request-changes", ['text' => ''], headers: $h)['code'])->toBe('invalid_request');

    $events = fsHttp('GET', "/envelopes/{$id}/events")['body'];
    $asked = array_values(array_filter($events, fn (array $e) => $e['type'] === 'signer.requested_changes'));
    expect($asked)->toHaveCount(1)
        ->and($asked[0]['data']['text'])->toBe('Please itemise the delivery.')
        ->and($told)->toContain('signer.requested_changes');
});
