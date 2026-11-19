<?php

declare(strict_types=1);

/*
 * per_page in data mode (CC-fancy-0052 section 7.2, owner Q1): not refused.
 * The organisation's lock generates one field per signer,
 * every-page-{kind}-{signer}, anchored {every_page: true}, completed once. Its
 * extent is the pages of the signer's latest rendering claim at signing,
 * recorded as per_page_pages; with no claim it is 1 (one mark).
 */

it('generates the every-page field under the organisation\'s lock', function () {
    $h = fsHarness();
    $h['signing']->setOrgSettings('org-1', ['per_page' => 'initials'], 'user:admin');
    $env = $h['signing']->create(fdmInput(), 'user:1', 'org-1');

    expect($env['settings']['per_page'])->toBe('initials')
        ->and(array_column($env['fields'], 'id'))->toBe(['alice-sig', 'alice-date', 'every-page-initials-alice'])
        ->and($env['fields'][2])->toMatchArray(['kind' => 'initials', 'anchor' => ['every_page' => true], 'required' => true, 'signer_id' => 'alice']);
    fsExpectError(fn () => $h['signing']->create(fdmInput(['settings' => ['mode' => 'data', 'per_page' => 'none']]), 'user:1', 'org-1'), 'invalid_request');
});

it('records per_page_pages from the latest rendering claim', function () {
    $h = fsHarness();
    $id = fdmSent($h, ['settings' => ['mode' => 'data', 'per_page' => 'signature']])['id'];
    $token = fdmInvite($h, $id, 'alice');
    $session = fdmOpen($h, $token, 'alice');
    fdmRender($h, $token, $session, 3);
    fdmRender($h, $token, $session, 5);
    fdmSign($h, $token, $session, 'alice');
    $record = $h['signing']->signatures($id)[0]['record'];

    expect($record['per_page_pages'])->toBe(5)
        ->and($record['fields'])->toBe(['alice-sig', 'alice-date', 'every-page-signature-alice']);
});

it('records one page (one mark) with no rendering claim, and for one continuous page', function () {
    $h = fsHarness();
    $id = fdmSent($h, ['settings' => ['mode' => 'data', 'per_page' => 'initials']], bob: true)['id'];
    $token = fdmInvite($h, $id, 'alice');
    fdmSign($h, $token, fdmOpen($h, $token, 'alice'), 'alice');
    fdmSignAs($h, $id, 'bob', 1);
    $records = array_column($h['signing']->signatures($id), 'record');

    expect(array_column($records, 'per_page_pages'))->toBe([1, 1])
        ->and($records[0]['rendered_seq'])->toBeNull()
        ->and($records[0]['renderer'])->toBeNull();
});

it('re-applies the organisation lock at send when it changed since the draft', function () {
    $h = fsHarness();
    $env = $h['signing']->create(fdmInput(), 'user:1', 'org-1');
    $h['signing']->setOrgSettings('org-1', ['per_page' => 'initials'], 'user:admin');
    $sent = $h['signing']->send($env['id'], 'user:1');

    expect(array_column($sent['fields'], 'id'))->toContain('every-page-initials-alice')
        ->and($sent['document']['fields_sha256'])->not->toBe($env['document']['fields_sha256']);
});
