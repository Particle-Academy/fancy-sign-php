<?php

declare(strict_types=1);

/*
 * invite(envelopeId, signerId, actor) (CC-fancy-0052 section 1.5): WHEN a
 * signer is invited is the host's workflow, so the host calls this. It mints a
 * link (replacing any older one), mails it through the host's SignMailer and
 * records email.sent (first time) or reminder.sent (again).
 */

it('refuses an invitation before send', function () {
    $h = fsHarness();
    $env = $h['signing']->create(fdmInput(), 'user:1');

    fsExpectError(fn () => $h['signing']->invite($env['id'], 'alice', 'user:1'), 'envelope_not_open');
    expect($h['mailer']->sent)->toBe([]);
});

it('refuses an invitation after completion', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    fdmSignAs($h, $id, 'alice');

    fsExpectError(fn () => $h['signing']->invite($id, 'alice', 'user:1'), 'envelope_not_open');
});

it('refuses to invite a signer who has signed, or one not on the envelope', function () {
    $h = fsHarness();
    $id = fdmSent($h, bob: true)['id'];
    fdmSignAs($h, $id, 'alice');

    fsExpectError(fn () => $h['signing']->invite($id, 'alice', 'user:1'), 'already_signed');
    fsExpectError(fn () => $h['signing']->invite($id, 'mallory', 'user:1'), 'not_found');
});

it('refuses to invite anyone once a signer has declined (the envelope is closed)', function () {
    $h = fsHarness();
    $id = fdmSent($h, bob: true)['id'];
    $token = fdmInvite($h, $id, 'alice');
    $session = fdmOpen($h, $token, 'alice');
    $h['signing']->decline($token, $session, 'No.', fsCtx());

    fsExpectError(fn () => $h['signing']->invite($id, 'alice', 'user:1'), 'envelope_not_open');
    fsExpectError(fn () => $h['signing']->invite($id, 'bob', 'user:1'), 'envelope_not_open');
});

it('records email.sent the first time, sets invited_at, and the token reaches only the mailer', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    $env = $h['signing']->invite($id, 'alice', 'user:1');
    $token = $h['mailer']->token('alice@example.com');
    $last = $h['signing']->events($id)[2];

    expect($last['type'])->toBe('email.sent')
        ->and($last['actor'])->toBe('user:1')
        ->and(fdmData($last))->toBe(['signer_id' => 'alice'])
        ->and($env['signers'][0]['invited_at'])->toBe('2026-11-13T12:00:00Z')
        ->and(json_encode($h['signing']->events($id)))->not->toContain($token)
        ->and(json_encode($h['repo']->envelope($id)))->not->toContain($token)
        ->and(json_encode($env))->not->toContain($token);
});

it('re-invites: a new link replaces the old one, recorded as reminder.sent', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    $old = fdmInvite($h, $id, 'alice');
    $new = fdmInvite($h, $id, 'alice');

    expect($new)->not->toBe($old)
        ->and(array_slice(fdmTypes($h, $id), -2))->toBe(['email.sent', 'reminder.sent']);
    fsExpectError(fn () => $h['signing']->requestCode($old, 'view', fsCtx()), 'link_invalid');
    expect($h['signing']->requestCode($new, 'view', fsCtx())['sent'])->toBeTrue();
});

it('refuses invite() in pdf mode: pdf send() already invites everyone', function () {
    $h = fsHarness();
    $env = fsSent($h);

    fsExpectError(fn () => $h['signing']->invite($env['id'], 'alice', 'user:1'), 'invalid_request');
});

it('remind() re-invites only the signers the host has invited, never anyone else', function () {
    $h = fsHarness();
    $id = fdmSent($h, bob: true)['id'];
    fdmInvite($h, $id, 'alice');
    $h['signing']->remind($id, 'user:1');

    expect(fdmLinked($h))->toBe(['alice', 'alice'])
        ->and(array_slice(fdmTypes($h, $id), -1))->toBe(['reminder.sent'])
        ->and($h['signing']->envelope($id)['signers'][1]['invited_at'])->toBeNull();
});

it('lets a pdf envelope report invited_at too, set when send() mails each signer', function () {
    $h = fsHarness();
    $env = fsSent($h, bob: true);

    expect(array_column($env['signers'], 'invited_at'))->toBe(['2026-11-13T12:00:00Z', '2026-11-13T12:00:00Z'])
        ->and(array_column($env['signers'], 'signature'))->toBe([null, null]);
});
