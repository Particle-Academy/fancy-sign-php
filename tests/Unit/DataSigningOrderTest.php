<?php

declare(strict_types=1);

/*
 * settings.order in data mode (CC-fancy-0052 section 7.1): a GUARD, never a
 * scheduler. Under sequential, invite() refuses a signer whose group is not
 * the current one (not_your_turn), and when a group finishes the next group
 * becomes pending and NOBODY is mailed: the host invites when its workflow
 * says so. Under parallel (the default) invite() takes anyone, in any order.
 */

it('sequential: a later group cannot be invited; after the first group signs it is pending, and no mail went out', function () {
    $h = fsHarness();
    $sent = fdmSent($h, ['settings' => ['mode' => 'data', 'order' => 'sequential']], bob: true);
    $id = $sent['id'];

    expect(array_column($sent['signers'], 'status'))->toBe(['pending', 'waiting']);
    fsExpectError(fn () => $h['signing']->invite($id, 'bob', 'user:1'), 'not_your_turn');

    fdmSignAs($h, $id, 'alice');
    $env = $h['signing']->envelope($id);
    $types = fdmTypes($h, $id);

    expect(array_column($env['signers'], 'status'))->toBe(['signed', 'pending'])
        ->and(fdmLinked($h))->toBe(['alice'])
        ->and(array_slice($types, -2))->toBe(['signer.signed', 'signature.sealed'])
        ->and(array_count_values($types)['email.sent'])->toBe(1);

    fdmSignAs($h, $id, 'bob');
    expect($h['signing']->envelope($id)['status'])->toBe('completed')
        ->and(fdmLinked($h))->toBe(['alice', 'bob']);
});

it('sequential: signers of the same order are one group, invited in any order within it', function () {
    $h = fsHarness();
    $input = fdmInput(['settings' => ['mode' => 'data', 'order' => 'sequential']], bob: true);
    $input['signers'][0]['order'] = 1;
    $input['signers'][1]['order'] = 1;
    $id = $h['signing']->send($h['signing']->create($input, 'user:1')['id'], 'user:1')['id'];

    fdmSignAs($h, $id, 'bob');
    fdmSignAs($h, $id, 'alice');
    expect($h['signing']->envelope($id)['status'])->toBe('completed');
});

it('parallel (the default): any signer may be invited and sign first', function () {
    $h = fsHarness();
    $sent = fdmSent($h, bob: true);

    expect($sent['settings']['order'])->toBe('parallel');
    fdmSignAs($h, $sent['id'], 'bob');
    fdmSignAs($h, $sent['id'], 'alice');
    expect($h['signing']->envelope($sent['id'])['status'])->toBe('completed')
        ->and(fdmLinked($h))->toBe(['bob', 'alice']);
});
