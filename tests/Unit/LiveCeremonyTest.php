<?php

declare(strict_types=1);

/*
 * The ceremony is live, never frozen per envelope (CC-fancy-0052 section 3.4,
 * D9): no envelope field, server response or stored value names a ceremony
 * version, template or layout, so a fancy-sign-ui upgrade or a new host
 * template reaches every open envelope with no resend. Pinned by allow-lists:
 * a new key here is a decision, not an accident.
 */

it('gives a data-mode SignerView data facts and field facts only', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    $token = fdmInvite($h, $id, 'alice');
    $session = fdmOpen($h, $token, 'alice');
    $view = $h['signing']->signerView($token, $session);

    expect(array_keys($view))->toBe(['envelope', 'signer', 'consent', 'adoption_text', 'changes_requested', 'max_change_requests', 'fields'])
        ->and(array_keys($view['envelope']))->toBe(['id', 'title', 'status', 'mode', 'data_sha256', 'sent_at'])
        ->and($view['envelope']['mode'])->toBe('data')
        ->and(array_keys($view['signer']))->toBe(['id', 'name', 'email', 'status', 'consent_at', 'signed_at', 'declined_at', 'signature'])
        ->and(array_keys($view['fields'][0]))->toBe(['id', 'signer_id', 'kind', 'anchor', 'required', 'label', 'placement', 'completed'])
        ->and(array_column($view['fields'], 'placement'))->toBe([null, null]);
});

it('stores and serves no presentation on a data-mode envelope', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    $env = $h['signing']->envelope($id);

    expect(array_keys($env))->toBe(['id', 'org_id', 'title', 'status', 'document', 'settings', 'signers', 'fields', 'chain_head', 'completion',
        'created_at', 'sent_at', 'completed_at', 'created_by'])
        ->and(array_keys($env['document']))->toBe(['mode', 'profile', 'canonical', 'data_sha256', 'data_bytes', 'fields_sha256', 'as_sent_sha256', 'final_sha256', 'pages'])
        ->and(array_keys($env['settings']))->toBe(['per_page', 'order', 'otp', 'step_up', 'level', 'seal', 'expires_at', 'mode'])
        ->and(array_keys($h['repo']->envelope($id)['document']))->toBe(['mode', 'profile', 'canonical', 'data_sha256', 'data_bytes', 'fields_sha256',
            'as_sent_sha256', 'as_sent_bytes', 'final_sha256', 'pages']);
});

it('reads an older envelope stored without settings.mode as pdf', function () {
    $h = fsHarness();
    $env = $h['signing']->create(fsHttpEnvelope(), 'user:1');
    $stored = $h['repo']->envelope($env['id']);
    unset($stored['settings']['mode']);
    $h['repo']->saveEnvelope($stored);
    $sent = $h['signing']->send($env['id'], 'user:1');

    expect($sent['document']['mode'])->toBe('pdf')
        ->and($sent['document']['as_sent_sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and(fdmLinked($h))->toBe(['alice']);
});
