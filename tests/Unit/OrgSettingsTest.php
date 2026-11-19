<?php

declare(strict_types=1);

/*
 * Per-page initials or signatures are an ORGANISATION setting: an org admin
 * turns them on, and every envelope of that org carries them. A sender cannot
 * turn them off for one envelope, and cannot pick a different kind. An
 * envelope with no organisation keeps the sender's own setting.
 */

function fsOrgEnvelope(array $h, ?string $org, array $settings = []): array
{
    return $h['signing']->create([
        'title' => 'Quote Q-1001', 'document' => fsDocument(),
        'signers' => [['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com']],
        'settings' => $settings,
    ], 'user:1', $org);
}

function fsEveryPage(array $env): array
{
    return array_values(array_filter($env['fields'], fn (array $f) => ($f['anchor']['every_page'] ?? false) === true));
}

it('defaults an organisation to no per-page marks', function () {
    $h = fsHarness();

    expect($h['signing']->orgSettings('org-1'))->toBe(['org_id' => 'org-1', 'per_page' => 'none', 'final_page' => 'always', 'order' => 'parallel', 'updated_by' => null, 'updated_at' => null]);
});

it('applies the org admin\'s per-page initials to every envelope of that org', function () {
    $h = fsHarness();
    $h['signing']->setOrgSettings('org-1', ['per_page' => 'initials'], 'user:admin');

    $env = fsOrgEnvelope($h, 'org-1');

    expect($env['settings']['per_page'])->toBe('initials')
        ->and(fsEveryPage($env))->toHaveCount(1)
        ->and(fsEveryPage($env)[0]['kind'])->toBe('initials');
});

it('refuses a sender who sets per_page against the organisation', function () {
    $h = fsHarness();
    $h['signing']->setOrgSettings('org-1', ['per_page' => 'signature'], 'user:admin');

    fsExpectError(fn () => fsOrgEnvelope($h, 'org-1', ['per_page' => 'none']), 'invalid_request');
    fsExpectError(fn () => fsOrgEnvelope($h, 'org-1', ['per_page' => 'initials']), 'invalid_request');
    expect(fsOrgEnvelope($h, 'org-1', ['per_page' => 'signature'])['settings']['per_page'])->toBe('signature');
});

it('leaves an envelope with no organisation to the sender', function () {
    $h = fsHarness();
    $h['signing']->setOrgSettings('org-1', ['per_page' => 'initials'], 'user:admin');

    expect(fsOrgEnvelope($h, null)['settings']['per_page'])->toBe('none')
        ->and(fsOrgEnvelope($h, null, ['per_page' => 'signature'])['settings']['per_page'])->toBe('signature');
});

it('takes the org setting in force when the envelope is SENT, not when the draft was made', function () {
    $h = fsHarness();
    $draft = fsOrgEnvelope($h, 'org-1');
    expect(fsEveryPage($draft))->toBe([]);

    $h['signing']->setOrgSettings('org-1', ['per_page' => 'initials'], 'user:admin');
    $sent = $h['signing']->send($draft['id'], 'user:1');

    expect($sent['settings']['per_page'])->toBe('initials')
        ->and(fsEveryPage($sent))->toHaveCount(1)
        ->and(fsEveryPage($sent)[0]['placement'])->not->toBe([]);
    foreach (fsEveryPage($sent)[0]['placement'] as $p) {
        expect($p['page'])->toBeLessThan($sent['document']['pages']);
    }
});

it('does not change an envelope that was already sent', function () {
    $h = fsHarness();
    $sent = $h['signing']->send(fsOrgEnvelope($h, 'org-1')['id'], 'user:1');
    $h['signing']->setOrgSettings('org-1', ['per_page' => 'initials'], 'user:admin');

    expect($h['signing']->envelope($sent['id'])['settings']['per_page'])->toBe('none');
});

it('validates the organisation setting', function () {
    $h = fsHarness();

    fsExpectError(fn () => $h['signing']->setOrgSettings('org-1', ['per_page' => 'stamp'], 'user:admin'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->setOrgSettings('org-1', ['per_page' => 'initials', 'extra' => 1], 'user:admin'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->setOrgSettings('', ['per_page' => 'initials'], 'user:admin'), 'invalid_request');

    $saved = $h['signing']->setOrgSettings('org-1', ['per_page' => 'signature'], 'user:admin');
    expect($saved['per_page'])->toBe('signature')->and($saved['updated_by'])->toBe('user:admin')->and($saved['updated_at'])->toBeString();
});
