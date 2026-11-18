<?php

declare(strict_types=1);

use FancySign\Chain\EventChain;

/*
 * Request changes (0.3.0, owner-approved q_8c180eab): beside Decline, the
 * signer sends a required, length-bounded message. It is a chained event,
 * `signer.requested_changes` {text}, it does NOT end the envelope (the signer
 * can still sign or decline), and a signer may ask again up to a cap.
 */

/** Open a session (view code) without consenting or opening the document. */
function fsSessionOnly(array $h, string $email = 'alice@example.com'): array
{
    $token = $h['mailer']->token($email);
    $h['signing']->requestCode($token, 'view', fsCtx());
    $session = $h['signing']->verifyCode($token, 'view', $h['mailer']->code($email, 'view'), fsCtx())['session'];

    return [$token, $session];
}

it('records the request as a chained signer.requested_changes event with the text, and keeps the envelope open', function () {
    $h = fsHarness();
    $env = fsSent($h);
    [$token, $session] = fsSessionOnly($h);

    $view = $h['signing']->requestChanges($token, $session, '  Please change the delivery date to March.  ', fsCtx());

    $events = $h['signing']->events($env['id']);
    $last = end($events);
    expect($last['type'])->toBe('signer.requested_changes')
        ->and($last['actor'])->toBe('signer:alice')
        ->and($last['source'])->toBe('server')
        ->and($last['data'])->toBe(['text' => 'Please change the delivery date to March.'])
        ->and(EventChain::verify($events)['ok'])->toBeTrue()
        ->and($view['signer']['status'])->not->toBe('declined')
        ->and($view['envelope']['status'])->toBe('sent')
        ->and($view['changes_requested'])->toBe(1);
});

it('still lets the signer sign after asking for changes', function () {
    $h = fsHarness();
    $env = fsSent($h);
    [$token, $session] = fsViewing($h);
    $h['signing']->requestChanges($token, $session, 'Could the total be itemised?', fsCtx());

    $signed = fsSign($h, $token, $session);

    expect($signed['signer']['status'])->toBe('signed')
        ->and($h['signing']->envelope($env['id'])['status'])->toBe('completed');
});

it('still lets the signer decline after asking for changes', function () {
    $h = fsHarness();
    fsSent($h);
    [$token, $session] = fsSessionOnly($h);
    $h['signing']->requestChanges($token, $session, 'Price too high.', fsCtx());

    expect($h['signing']->decline($token, $session, 'No reply to my request.', fsCtx())['signer']['status'])->toBe('declined');
});

it('needs the view code first (a session), exactly as decline does', function () {
    $h = fsHarness();
    fsSent($h);
    $token = $h['mailer']->token('alice@example.com');

    fsExpectError(fn () => $h['signing']->requestChanges($token, null, 'Change it.', fsCtx()), 'session_required');
    fsExpectError(fn () => $h['signing']->requestChanges('not-a-token', 'x', 'Change it.', fsCtx()), 'link_invalid');
});

it('is refused after signing and after declining', function () {
    $h = fsHarness();
    fsSent($h, bob: true);
    [$token, $session] = fsViewing($h);
    fsSign($h, $token, $session);
    fsExpectError(fn () => $h['signing']->requestChanges($token, $session, 'Too late.', fsCtx()), 'already_signed');

    [$bobToken, $bobSession] = fsSessionOnly($h, 'bob@example.com');
    $h['signing']->decline($bobToken, $bobSession, 'No.', fsCtx());
    // A decline ends the whole envelope, so it answers as decline itself would after it.
    fsExpectError(fn () => $h['signing']->requestChanges($bobToken, $bobSession, 'Too late.', fsCtx()), 'envelope_not_open');
});

it('requires the text, bounded like the decline reason', function () {
    $h = fsHarness(['max_decline_reason' => 20]);
    fsSent($h);
    [$token, $session] = fsSessionOnly($h);

    fsExpectError(fn () => $h['signing']->requestChanges($token, $session, '   ', fsCtx()), 'invalid_request');
    fsExpectError(fn () => $h['signing']->requestChanges($token, $session, null, fsCtx()), 'invalid_request');
    fsExpectError(fn () => $h['signing']->requestChanges($token, $session, str_repeat('x', 21), fsCtx()), 'invalid_request');
    expect($h['signing']->requestChanges($token, $session, str_repeat('x', 20), fsCtx())['changes_requested'])->toBe(1);
});

it('allows asking again up to the cap, which is a setting, then refuses with limit_exceeded', function () {
    $h = fsHarness(['max_change_requests' => 2]);
    $env = fsSent($h);
    [$token, $session] = fsSessionOnly($h);

    $h['signing']->requestChanges($token, $session, 'First.', fsCtx());
    $h['signing']->requestChanges($token, $session, 'Second.', fsCtx());
    fsExpectError(fn () => $h['signing']->requestChanges($token, $session, 'Third.', fsCtx()), 'limit_exceeded');

    $asked = array_filter($h['signing']->events($env['id']), fn (array $e) => $e['type'] === 'signer.requested_changes');
    expect($asked)->toHaveCount(2);
});

it('defaults the cap to 5', function () {
    expect((new FancySign\Config())->int('max_change_requests'))->toBe(5);
});

it('counts the cap per signer', function () {
    $h = fsHarness(['max_change_requests' => 1]);
    fsSent($h, bob: true);
    [$a, $as] = fsSessionOnly($h);
    [$b, $bs] = fsSessionOnly($h, 'bob@example.com');

    $h['signing']->requestChanges($a, $as, 'Alice asks.', fsCtx());
    expect($h['signing']->requestChanges($b, $bs, 'Bob asks.', fsCtx())['changes_requested'])->toBe(1);
});
