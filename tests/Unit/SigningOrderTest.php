<?php

declare(strict_types=1);

use FancySign\SignError;

/*
 * settings.order (CC-fancy-0051, owner-approved): "parallel" | "sequential".
 *
 * - parallel is the default and is 0.5.0 exactly: every signer is mailed at
 *   send, any of them may sign first, and a signer's `order` is the list
 *   position whatever the input says.
 * - sequential: send() invites only the lowest-order group; signers with the
 *   same `order` sign in parallel within their group; when the whole group has
 *   signed, the next group is invited (email.sent, actor system).
 *   signer.signed fires per signature; envelope.completed only after the last.
 * - A signer not yet invited is `waiting`, has no link, and a link opened out
 *   of turn is refused with `not_your_turn` (409) before anything is shown.
 */

/** Alice, Bob and Carol, with optional explicit orders. */
function fsoSigners(?array $orders = null): array
{
    $out = [];
    foreach (['alice', 'bob', 'carol'] as $i => $id) {
        $s = ['id' => $id, 'name' => ucfirst($id).' Example', 'email' => "{$id}@example.com"];
        if ($orders !== null && array_key_exists($i, $orders) && $orders[$i] !== null) {
            $s['order'] = $orders[$i];
        }
        $out[] = $s;
    }

    return $out;
}

function fsoSent(array $h, array $settings, ?array $orders = null, int $count = 3, ?string $org = null): array
{
    $env = $h['signing']->create(['title' => 'Quote Q-1001', 'document' => fsDocument(),
        'signers' => array_slice(fsoSigners($orders), 0, $count), 'settings' => $settings], 'user:1', $org);

    return $h['signing']->send($env['id'], 'user:1');
}

/** Who has been mailed a link, in order. */
function fsoLinked(array $h): array
{
    return array_values(array_map(fn (array $m) => strstr($m['to'], '@', true), array_filter($h['mailer']->sent, fn (array $m) => $m['kind'] === 'link')));
}

function fsoStatuses(array $env): array
{
    return array_column($env['signers'], 'status', 'id');
}

function fsoTypes(array $h, string $id): array
{
    return array_column($h['signing']->events($id), 'type');
}

function fsoSignAs(array $h, string $who): array
{
    [$token, $session] = fsViewing($h, "{$who}@example.com");

    return fsSign($h, $token, $session, "{$who}@example.com");
}

// ------------------------------------------------------------------ parallel

it('defaults settings.order to parallel', function () {
    $h = fsHarness();
    $env = $h['signing']->create(['title' => 'T', 'document' => fsDocument(), 'signers' => fsoSigners()], 'user:1');

    expect($env['settings']['order'])->toBe('parallel');
});

it('keeps parallel byte for byte 0.5.0: every signer mailed at send, any may sign first, the same events', function () {
    $h = fsHarness();
    $env = fsoSent($h, [], count: 2);

    expect(fsoLinked($h))->toBe(['alice', 'bob'])
        ->and(fsoStatuses($env))->toBe(['alice' => 'pending', 'bob' => 'pending'])
        ->and(array_column($env['signers'], 'order'))->toBe([1, 2]);

    fsoSignAs($h, 'bob');
    expect($h['signing']->envelope($env['id'])['status'])->toBe('sent');
    fsoSignAs($h, 'alice');

    // The exact 0.5.0 chain for this run (pinned against 0.5.0 before the change).
    expect(fsoTypes($h, $env['id']))->toBe([
        'envelope.created', 'envelope.sent', 'email.sent', 'email.sent',
        'link.opened', 'otp.sent', 'otp.verified', 'consent.accepted', 'document.opened', 'otp.sent',
        'otp.verified', 'field.completed', 'field.completed', 'mark.adopted', 'signer.signed',
        'link.opened', 'otp.sent', 'otp.verified', 'consent.accepted', 'document.opened', 'otp.sent',
        'otp.verified', 'field.completed', 'field.completed', 'field.completed', 'field.completed', 'mark.adopted', 'signer.signed',
        'envelope.completed', 'seal.applied',
    ])->and($h['signing']->envelope($env['id'])['status'])->toBe('completed')
        ->and(fsoLinked($h))->toBe(['alice', 'bob']);
});

it('keeps parallel ignoring a signer order in the input, as 0.5.0 did', function () {
    $h = fsHarness();
    $env = $h['signing']->create(['title' => 'T', 'document' => fsDocument(), 'signers' => fsoSigners([5, 'x', 5])], 'user:1');

    expect(array_column($env['signers'], 'order'))->toBe([1, 2, 3]);
});

it('reads an envelope stored before settings.order existed as parallel', function () {
    $h = fsHarness();
    $env = $h['signing']->create(['title' => 'T', 'document' => fsDocument(), 'signers' => fsoSigners()], 'user:1');
    $stored = $h['repo']->envelope($env['id']);
    unset($stored['settings']['order']);
    $h['repo']->saveEnvelope($stored);

    $h['signing']->send($env['id'], 'user:1');

    expect(fsoLinked($h))->toBe(['alice', 'bob', 'carol']);
});

// ---------------------------------------------------------------- sequential

it('invites only the first signer on send; the rest wait, with no link', function () {
    $h = fsHarness();
    $env = fsoSent($h, ['order' => 'sequential']);

    expect($env['settings']['order'])->toBe('sequential')
        ->and(fsoLinked($h))->toBe(['alice'])
        ->and(fsoStatuses($env))->toBe(['alice' => 'pending', 'bob' => 'waiting', 'carol' => 'waiting'])
        ->and(array_count_values(fsoTypes($h, $env['id']))['email.sent'])->toBe(1);
});

it('invites the next signer when the previous one signs, and completes only after the last', function () {
    $h = fsHarness();
    $env = fsoSent($h, ['order' => 'sequential'], count: 2);

    fsoSignAs($h, 'alice');
    $after = $h['signing']->envelope($env['id']);
    $events = $h['signing']->events($env['id']);
    $last = end($events);

    expect(fsoLinked($h))->toBe(['alice', 'bob'])
        ->and($after['status'])->toBe('sent')
        ->and(fsoStatuses($after))->toBe(['alice' => 'signed', 'bob' => 'pending'])
        ->and(fsoTypes($h, $env['id']))->not->toContain('envelope.completed')
        ->and($last['type'])->toBe('email.sent')
        ->and($last['actor'])->toBe('system')
        ->and($last['data'])->toBe(['signer_id' => 'bob']);

    fsoSignAs($h, 'bob');
    $types = fsoTypes($h, $env['id']);

    expect($h['signing']->envelope($env['id'])['status'])->toBe('completed')
        ->and(array_count_values($types)['signer.signed'])->toBe(2)
        ->and(array_count_values($types)['envelope.completed'])->toBe(1)
        ->and(array_search('envelope.completed', $types, true))->toBeGreaterThan(array_keys($types, 'signer.signed', true)[1]);
});

it('signs equal orders in parallel within their group, and moves on only when the whole group has signed', function () {
    $h = fsHarness();
    $env = fsoSent($h, ['order' => 'sequential'], [1, 1, 2]);

    expect(fsoLinked($h))->toBe(['alice', 'bob'])
        ->and(fsoStatuses($env))->toBe(['alice' => 'pending', 'bob' => 'pending', 'carol' => 'waiting']);

    fsoSignAs($h, 'bob');
    expect(fsoLinked($h))->toBe(['alice', 'bob'])
        ->and(fsoStatuses($h['signing']->envelope($env['id']))['carol'])->toBe('waiting');

    fsoSignAs($h, 'alice');
    expect(fsoLinked($h))->toBe(['alice', 'bob', 'carol']);

    fsoSignAs($h, 'carol');
    expect($h['signing']->envelope($env['id'])['status'])->toBe('completed');
});

it('follows the orders given, not the list position', function () {
    $h = fsHarness();
    $env = fsoSent($h, ['order' => 'sequential'], [3, 1, 2]);

    expect(array_column($env['signers'], 'order', 'id'))->toBe(['alice' => 3, 'bob' => 1, 'carol' => 2])
        ->and(fsoLinked($h))->toBe(['bob']);
    fsoSignAs($h, 'bob');
    expect(fsoLinked($h))->toBe(['bob', 'carol']);
    fsoSignAs($h, 'carol');
    expect(fsoLinked($h))->toBe(['bob', 'carol', 'alice']);
});

it('defaults a signer with no order to its list position under sequential', function () {
    $h = fsHarness();
    $env = fsoSent($h, ['order' => 'sequential'], [null, 1, null]);

    expect(array_column($env['signers'], 'order', 'id'))->toBe(['alice' => 1, 'bob' => 1, 'carol' => 3])
        ->and(fsoLinked($h))->toBe(['alice', 'bob']);
});

it('refuses a link opened out of turn with not_your_turn, before any code, event or document', function () {
    $h = fsHarness();
    $env = fsoSent($h, ['order' => 'sequential'], count: 2);
    // Bob was never mailed a link; plant one as a host or a replayed store might.
    $h['repo']->putLink(hash('sha256', 'bob-early-token'), $env['id'], 'bob');
    $before = count($h['signing']->events($env['id']));
    $mails = count($h['mailer']->sent);

    foreach ([
        fn () => $h['signing']->requestCode('bob-early-token', 'view', fsCtx()),
        fn () => $h['signing']->verifyCode('bob-early-token', 'view', '123456', fsCtx()),
        fn () => $h['signing']->signerView('bob-early-token', 'x'),
        fn () => $h['signing']->document('bob-early-token', 'x', fsCtx()),
        fn () => $h['signing']->consent('bob-early-token', 'x', '1', fsCtx()),
        fn () => $h['signing']->decline('bob-early-token', 'x', 'no', fsCtx()),
        fn () => $h['signing']->requestChanges('bob-early-token', 'x', 'change', fsCtx()),
        fn () => $h['signing']->sign('bob-early-token', 'x', [], fsCtx()),
    ] as $call) {
        fsExpectError($call, 'not_your_turn');
    }

    expect(count($h['signing']->events($env['id'])))->toBe($before)
        ->and(count($h['mailer']->sent))->toBe($mails)
        ->and(SignError::CODES['not_your_turn'])->toBe(409)
        ->and((new SignError('not_your_turn', 'm'))->envelope('r1'))->toBe(['error' => 'not_your_turn', 'message' => 'm', 'requestId' => 'r1']);
});

it('stops the sequence on a decline: the envelope is declined and the next signer is never invited', function () {
    $h = fsHarness();
    $env = fsoSent($h, ['order' => 'sequential']);
    fsoSignAs($h, 'alice');
    $token = $h['mailer']->token('bob@example.com');
    $h['signing']->requestCode($token, 'view', fsCtx());
    $session = $h['signing']->verifyCode($token, 'view', $h['mailer']->code('bob@example.com', 'view'), fsCtx())['session'];

    $h['signing']->decline($token, $session, 'Not this quote.', fsCtx());

    $after = $h['signing']->envelope($env['id']);
    expect($after['status'])->toBe('declined')
        ->and(fsoStatuses($after)['carol'])->toBe('waiting')
        ->and(fsoLinked($h))->toBe(['alice', 'bob']);
});

it('holds the sequence while changes are requested: the next signer still waits', function () {
    $h = fsHarness();
    $env = fsoSent($h, ['order' => 'sequential'], count: 2);
    [$token, $session] = fsViewing($h);

    $h['signing']->requestChanges($token, $session, 'Please change the date.', fsCtx());

    expect($h['signing']->envelope($env['id'])['status'])->toBe('sent')
        ->and(fsoStatuses($h['signing']->envelope($env['id']))['bob'])->toBe('waiting')
        ->and(fsoLinked($h))->toBe(['alice']);
});

it('reminds only the invited signers who have not finished, never the waiting ones', function () {
    $h = fsHarness();
    $env = fsoSent($h, ['order' => 'sequential'], [1, 1, 2]);
    fsoSignAs($h, 'alice');

    $h['signing']->remind($env['id'], 'user:1');

    expect(fsoLinked($h))->toBe(['alice', 'bob', 'bob'])
        ->and(fsoStatuses($h['signing']->envelope($env['id']))['carol'])->toBe('waiting');
});

it('voids a sequence with waiting signers: nobody else is invited and every link stops', function () {
    $h = fsHarness();
    $env = fsoSent($h, ['order' => 'sequential'], count: 2);
    $token = $h['mailer']->token('alice@example.com');

    $voided = $h['signing']->void($env['id'], 'Sent by mistake.', 'user:1');

    expect($voided['status'])->toBe('voided')
        ->and(fsoStatuses($voided)['bob'])->toBe('waiting')
        ->and(fsoLinked($h))->toBe(['alice']);
    fsExpectError(fn () => $h['signing']->requestCode($token, 'view', fsCtx()), 'envelope_not_open');
    fsExpectError(fn () => $h['signing']->remind($env['id'], 'user:1'), 'envelope_not_open');
});

it('keeps the orders through a draft update', function () {
    $h = fsHarness();
    $env = $h['signing']->create(['title' => 'T', 'document' => fsDocument(), 'signers' => fsoSigners([2, 1, 1]), 'settings' => ['order' => 'sequential']], 'user:1');

    $updated = $h['signing']->update($env['id'], ['title' => 'T2'], 'user:1');

    expect(array_column($updated['signers'], 'order', 'id'))->toBe(['alice' => 2, 'bob' => 1, 'carol' => 1])
        ->and($updated['settings']['order'])->toBe('sequential');
});

// ---------------------------------------------------------------- validation

it('validates settings.order like the other settings', function (mixed $order) {
    $h = fsHarness();

    fsExpectError(fn () => $h['signing']->create(['title' => 'T', 'document' => fsDocument(), 'signers' => fsoSigners(), 'settings' => ['order' => $order]], 'user:1'), 'invalid_request');
})->with([['random'], [''], [null], [1], [['sequential']]]);

it('validates a signer order under sequential: a whole number from 1', function (mixed $order) {
    $h = fsHarness();

    fsExpectError(fn () => $h['signing']->create(['title' => 'T', 'document' => fsDocument(), 'signers' => fsoSigners([$order]), 'settings' => ['order' => 'sequential']], 'user:1'), 'invalid_request');
})->with([[0], [-1], ['1'], [1.5], [true], [1000001]]);

// -------------------------------------------------------------- organisation

it('takes the organisation default for order, which a sender may override per envelope', function () {
    $h = fsHarness();
    expect($h['signing']->orgSettings('org-1')['order'])->toBe('parallel');

    $saved = $h['signing']->setOrgSettings('org-1', ['order' => 'sequential'], 'user:admin');
    expect($saved['order'])->toBe('sequential')
        ->and($saved['final_page'])->toBe('always');

    $env = fsoSent($h, [], count: 2, org: 'org-1');
    expect($env['settings']['order'])->toBe('sequential')
        ->and(fsoLinked($h))->toBe(['alice']);

    $own = $h['signing']->create(['title' => 'T', 'document' => fsDocument(), 'signers' => fsoSigners(), 'settings' => ['order' => 'parallel']], 'user:1', 'org-1');
    expect($own['settings']['order'])->toBe('parallel');
});

it('keeps the org order when a settings update leaves it out, and refuses a bad one', function () {
    $h = fsHarness();
    $h['signing']->setOrgSettings('org-1', ['order' => 'sequential'], 'user:admin');

    expect($h['signing']->setOrgSettings('org-1', ['per_page' => 'initials'], 'user:admin')['order'])->toBe('sequential');
    fsExpectError(fn () => $h['signing']->setOrgSettings('org-1', ['order' => 'random'], 'user:admin'), 'invalid_request');
});

it('reads an org row saved before order existed as parallel', function () {
    $h = fsHarness();
    $h['repo']->saveOrgSettings(['org_id' => 'org-1', 'per_page' => 'none', 'final_page' => 'auto', 'updated_by' => 'user:admin', 'updated_at' => '2026-11-19T00:00:00Z']);

    expect($h['signing']->orgSettings('org-1')['order'])->toBe('parallel');
});
