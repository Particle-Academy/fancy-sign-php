<?php

declare(strict_types=1);

use FancySign\Data\CanonicalDocument;

/*
 * What data mode refuses, and when (CC-fancy-0052 sections 1.2, 3.3, 7.3).
 * A refusal at create stores nothing.
 */

it('requires the host\'s data_sha256, and a wrong one is data_hash_mismatch with nothing stored', function () {
    $h = fsHarness();
    $input = fdmInput();
    unset($input['data_sha256']);

    fsExpectError(fn () => $h['signing']->create($input, 'user:1'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->create(fdmInput(['data_sha256' => str_repeat('0', 64)]), 'user:1'), 'data_hash_mismatch');
    fsExpectError(fn () => $h['signing']->create(fdmInput(['data_sha256' => 'not-a-hash']), 'user:1'), 'invalid_request');
    expect(fsPvRows($h['repo'])['envelopes'])->toBe(0)->and(fsPvRows($h['repo'])['events'])->toBe(0);
});

it('refuses data that is not a JSON object, and an associative array (it cannot tell {} from [])', function () {
    $h = fsHarness();
    // The control: the same harness accepts a well-formed data envelope, so the refusals below are data mode's own.
    expect($h['signing']->create(fdmInput(), 'user:1')['document']['mode'])->toBe('data');

    fsExpectError(fn () => $h['signing']->create(fdmInput(['data' => '[1, 2]']), 'user:1'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->create(fdmInput(['data' => '{"n": 9007199254740993}']), 'user:1'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->create(fdmInput(['data' => json_decode(fdmQuote(), true)]), 'user:1'), 'invalid_request');
    $input = fdmInput();
    unset($input['data']);
    fsExpectError(fn () => $h['signing']->create($input, 'user:1'), 'invalid_request');
});

it('accepts the data as a stdClass tree as well as JSON text', function () {
    $env = fsHarness()['signing']->create(fdmInput(['data' => json_decode(fdmQuote(), false)]), 'user:1');

    expect($env['document']['data_sha256'])->toBe(FDM_QUOTE_SHA);
});

it('refuses a field pointer that does not resolve in the data, and accepts every RFC 6901 form that does', function () {
    $h = fsHarness();
    $field = fn (string $pointer) => fdmInput(['fields' => [['id' => 'sig', 'signer' => 'alice', 'kind' => 'signature', 'anchor' => ['pointer' => $pointer]]]]);

    foreach (['/nope', '/lines/2', '/lines/01', '/lines/-', 'lines', '/totals/net/x'] as $bad) {
        fsExpectError(fn () => $h['signing']->create($field($bad), 'user:1'), 'invalid_request');
    }
    foreach (['', '/lines/0/unit', '/customer/tags', '/terms/1'] as $good) {
        expect($h['signing']->create($field($good), 'user:1')['fields'][0]['anchor'])->toBe(['pointer' => $good]);
    }
    $escaped = '{"a/b": {"m~n": 1}}';
    $env = $h['signing']->create(fdmInput(['data' => $escaped, 'data_sha256' => CanonicalDocument::sha256($escaped),
        'fields' => [['id' => 'sig', 'signer' => 'alice', 'kind' => 'signature', 'anchor' => ['pointer' => '/a~1b/m~0n']]]]), 'user:1');
    expect($env['fields'][0]['anchor']['pointer'])->toBe('/a~1b/m~0n');
});

it('refuses a signer without a required signature field, and malformed fields', function () {
    $h = fsHarness();
    // The control: the same harness accepts a well-formed data envelope, so the refusals below are data mode's own.
    expect($h['signing']->create(fdmInput(), 'user:1')['document']['mode'])->toBe('data');
    $f = fn (array $fields) => fdmInput(['fields' => $fields]);

    fsExpectError(fn () => $h['signing']->create($f([['id' => 'd', 'signer' => 'alice', 'kind' => 'date_signed']]), 'user:1'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->create($f([['id' => 's', 'signer' => 'alice', 'kind' => 'signature', 'required' => false]]), 'user:1'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->create($f([['id' => 's', 'signer' => 'mallory', 'kind' => 'signature']]), 'user:1'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->create($f([['id' => 's', 'signer' => 'alice', 'kind' => 'stamp']]), 'user:1'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->create($f([['id' => 'bad id', 'signer' => 'alice', 'kind' => 'signature']]), 'user:1'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->create($f([['id' => 's', 'signer' => 'alice', 'kind' => 'signature'], ['id' => 's', 'signer' => 'alice', 'kind' => 'initials']]), 'user:1'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->create($f([['id' => 's', 'signer' => 'alice', 'kind' => 'signature', 'anchor' => ['node' => 'x']]]), 'user:1'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->create($f([['id' => 's', 'signer' => 'alice', 'kind' => 'signature', 'placement' => []]]), 'user:1'), 'invalid_request');
});

it('refuses final_page in data mode: it is a PDF layout concept', function () {
    $h = fsHarness();
    // The control: the same harness accepts a well-formed data envelope, so the refusals below are data mode's own.
    expect($h['signing']->create(fdmInput(), 'user:1')['document']['mode'])->toBe('data');
    fsExpectError(fn () => fsHarness()['signing']->create(fdmInput(['settings' => ['mode' => 'data', 'final_page' => 'never']]), 'user:1'), 'invalid_request');
});

it('ignores the organisation\'s final_page default in data mode, which is a default and not a lock', function () {
    $h = fsHarness();
    $h['signing']->setOrgSettings('org-1', ['final_page' => 'never'], 'user:admin');

    expect($h['signing']->create(fdmInput(), 'user:1', 'org-1')['settings'])->not->toHaveKey('final_page');
});

it('refuses a mode that is not pdf or data', function () {
    fsExpectError(fn () => fsHarness()['signing']->create(fdmInput(['settings' => ['mode' => 'docx']]), 'user:1'), 'invalid_request');
    fsExpectError(fn () => fsHarness()['signing']->create(fsHttpEnvelope() + ['settings' => ['mode' => 'docx']], 'user:1'), 'invalid_request');
});

it('refuses preview() and previewDocument() in data mode: there is no as-sent document', function () {
    $h = fsHarness();
    expect($h['signing']->previewData(fdmInput())['data_sha256'])->toBe(FDM_QUOTE_SHA);

    fsExpectError(fn () => $h['signing']->preview(fdmInput()), 'invalid_request');
    fsExpectError(fn () => $h['signing']->previewDocument(fdmInput()), 'invalid_request');
});

it('has no as-sent or final PDF in data mode', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    fdmSignAs($h, $id, 'alice');

    fsExpectError(fn () => $h['signing']->asSentDocument($id), 'not_found');
    fsExpectError(fn () => $h['signing']->finalDocument($id), 'not_found');
    fsExpectError(fn () => $h['signing']->currentDocument($id), 'not_found');
});

it('refuses a wrong data_sha256 at sign BEFORE the accept code is consumed', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    $token = fdmInvite($h, $id, 'alice');
    $session = fdmOpen($h, $token, 'alice');
    $h['signing']->requestCode($token, 'accept', fsCtx(), $session);
    $code = $h['mailer']->code('alice@example.com', 'accept');
    $input = ['fields' => ['alice-sig' => true, 'alice-date' => true], 'mark' => fsMark(), 'adoption' => $h['signing']->config()->adoptionText(), 'code' => $code];

    fsExpectError(fn () => $h['signing']->sign($token, $session, $input + ['data_sha256' => str_repeat('a', 64)], fsCtx()), 'data_hash_mismatch');
    fsExpectError(fn () => $h['signing']->sign($token, $session, $input, fsCtx()), 'invalid_request');
    expect(fdmTypes($h, $id))->not->toContain('otp.failed')
        ->and($h['signing']->sign($token, $session, $input + ['data_sha256' => FDM_QUOTE_SHA], fsCtx())['signer']['status'])->toBe('signed');
});

it('checks the rendering claim: opened first, the stored hash, a renderer string and a page count', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    $token = fdmInvite($h, $id, 'alice');
    $h['signing']->requestCode($token, 'view', fsCtx());
    $session = $h['signing']->verifyCode($token, 'view', $h['mailer']->code('alice@example.com', 'view'), fsCtx())['session'];
    $h['signing']->consent($token, $session, $h['signing']->config()->consentVersion(), fsCtx());
    $claim = fn (array $over) => fn () => $h['signing']->documentRendered($token, $session, $over + ['data_sha256' => FDM_QUOTE_SHA, 'renderer' => 'r', 'pages' => 1], fsCtx());

    fsExpectError($claim([]), 'not_viewed');
    $h['signing']->document($token, $session, fsCtx());
    fsExpectError($claim(['data_sha256' => str_repeat('b', 64)]), 'data_hash_mismatch');
    fsExpectError($claim(['renderer' => '']), 'invalid_request');
    fsExpectError($claim(['renderer' => str_repeat('x', 201)]), 'invalid_request');
    fsExpectError($claim(['pages' => 0]), 'invalid_request');
    fsExpectError($claim(['pages' => '2']), 'invalid_request');
    fsExpectError($claim(['pages' => 1001]), 'invalid_request');
    fsExpectError(fn () => $h['signing']->documentRendered($token, null, ['data_sha256' => FDM_QUOTE_SHA, 'renderer' => 'r', 'pages' => 1], fsCtx()), 'session_required');

    $claim(['renderer' => "quote\x00-v7\n @ 1.0"])();
    $events = $h['signing']->events($id);
    expect(fdmData($events[count($events) - 1])['renderer'])->toBe('quote-v7 @ 1.0');
});

it('refuses a rendering claim on a pdf envelope', function () {
    $h = fsHarness();
    fsSent($h);
    [$token, $session] = fsViewing($h);

    fsExpectError(fn () => $h['signing']->documentRendered($token, $session, ['data_sha256' => FDM_QUOTE_SHA, 'renderer' => 'r', 'pages' => 1], fsCtx()), 'invalid_request');
});

it('bounds page.viewed by the latest rendering claim: no claim, no page claims', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    $token = fdmInvite($h, $id, 'alice');
    $session = fdmOpen($h, $token, 'alice');

    fsExpectError(fn () => $h['signing']->pageViewed($token, $session, 1, 1000, fsCtx()), 'invalid_request');
    fdmRender($h, $token, $session, 2);
    $h['signing']->pageViewed($token, $session, 2, 1000, fsCtx());
    fsExpectError(fn () => $h['signing']->pageViewed($token, $session, 3, 1000, fsCtx()), 'invalid_request');
    expect(fdmTypes($h, $id))->toContain('page.viewed');
});

it('applies every data limit as a setting', function () {
    fsExpectError(fn () => fsHarness(['data' => ['max_bytes' => 271]])['signing']->create(fdmInput(), 'user:1'), 'invalid_request');
    expect(fsHarness(['data' => ['max_bytes' => 272]])['signing']->create(fdmInput(), 'user:1')['status'])->toBe('draft');

    // The quote nests four deep: the object, lines, a line, its options {}.
    fsExpectError(fn () => fsHarness(['data' => ['max_depth' => 3]])['signing']->create(fdmInput(), 'user:1'), 'invalid_request');
    expect(fsHarness(['data' => ['max_depth' => 4]])['signing']->create(fdmInput(), 'user:1')['status'])->toBe('draft');

    fsExpectError(fn () => fsHarness(['data' => ['max_fields' => 1]])['signing']->create(fdmInput(), 'user:1'), 'invalid_request');

    $h = fsHarness(['data' => ['max_pages' => 3]]);
    $id = fdmSent($h)['id'];
    $token = fdmInvite($h, $id, 'alice');
    $session = fdmOpen($h, $token, 'alice');
    fsExpectError(fn () => fdmRender($h, $token, $session, 4), 'invalid_request');
    fdmRender($h, $token, $session, 3);

    $h = fsHarness(['page_views' => ['per_signer' => 2]]);
    $id = fdmSent($h)['id'];
    $token = fdmInvite($h, $id, 'alice');
    $session = fdmOpen($h, $token, 'alice');
    fdmRender($h, $token, $session);
    fdmRender($h, $token, $session);
    fsExpectError(fn () => fdmRender($h, $token, $session), 'limit_exceeded');
});
