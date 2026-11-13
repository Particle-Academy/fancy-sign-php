<?php

declare(strict_types=1);

/*
 * The failure paths, written before the code (the brief's list, in order):
 * OTP replay, expiry and wrong purpose; accepting without viewing; a signer
 * reaching into another envelope; a reused token. Tampering is in VerifyTest.
 */

// ---------------------------------------------------------------- one-time codes

it('refuses a replayed view code: verify consumes it', function () {
    $h = fsHarness();
    fsSent($h);
    $token = $h['mailer']->token('alice@example.com');
    $h['signing']->requestCode($token, 'view', fsCtx());
    $code = $h['mailer']->code('alice@example.com', 'view');

    $h['signing']->verifyCode($token, 'view', $code, fsCtx());
    fsExpectError(fn () => $h['signing']->verifyCode($token, 'view', $code, fsCtx()), 'otp_invalid');
});

it('refuses an expired code, and says only otp_invalid on the wire', function () {
    $h = fsHarness();
    fsSent($h);
    $token = $h['mailer']->token('alice@example.com');
    $h['signing']->requestCode($token, 'view', fsCtx());
    $h['clock']->advance(601);

    fsExpectError(fn () => $h['signing']->verifyCode($token, 'view', $h['mailer']->code('alice@example.com', 'view'), fsCtx()), 'otp_invalid');

    // The precise reason survives in the event, never on the wire.
    $failed = array_values(array_filter($h['signing']->events(fsEnvelopeId($h)), fn (array $e) => $e['type'] === 'otp.failed'));
    expect($failed[0]['data']['reason'])->toBe('otp_expired');
});

it('honours a configured expiry instead of the default', function () {
    $h = fsHarness(['otp' => ['ttl_seconds' => 60]]);
    fsSent($h);
    $token = $h['mailer']->token('alice@example.com');
    $h['signing']->requestCode($token, 'view', fsCtx());
    $h['clock']->advance(61);

    fsExpectError(fn () => $h['signing']->verifyCode($token, 'view', $h['mailer']->code('alice@example.com', 'view'), fsCtx()), 'otp_invalid');
});

it('refuses a view code at accept, and an accept code at view (purpose-bound)', function () {
    $h = fsHarness();
    fsSent($h);
    [$token, $session] = fsViewing($h);

    // A view code cannot sign.
    $h['signing']->requestCode($token, 'view', fsCtx());
    $viewCode = $h['mailer']->code('alice@example.com', 'view');
    $view = $h['signing']->signerView($token, $session);
    $fields = array_fill_keys(array_column($view['fields'], 'id'), true);
    fsExpectError(fn () => $h['signing']->sign($token, $session, [
        'fields' => $fields, 'mark' => fsMark(), 'adoption' => $h['signing']->config()->adoptionText(), 'code' => $viewCode,
    ], fsCtx()), 'otp_invalid');

    // An accept code cannot open a session.
    $h['signing']->requestCode($token, 'accept', fsCtx(), $session);
    $acceptCode = $h['mailer']->code('alice@example.com', 'accept');
    fsExpectError(fn () => $h['signing']->verifyCode($token, 'accept', $acceptCode, fsCtx()), 'invalid_request');
    fsExpectError(fn () => $h['signing']->verifyCode($token, 'view', $acceptCode, fsCtx()), 'otp_invalid');
});

it('locks after the configured number of wrong codes, and only then says otp_locked', function () {
    $h = fsHarness(['otp' => ['max_attempts' => 3]]);
    fsSent($h);
    $token = $h['mailer']->token('alice@example.com');
    $h['signing']->requestCode($token, 'view', fsCtx());
    $right = $h['mailer']->code('alice@example.com', 'view');
    $wrong = $right === '000000' ? '111111' : '000000';

    fsExpectError(fn () => $h['signing']->verifyCode($token, 'view', $wrong, fsCtx()), 'otp_invalid');
    fsExpectError(fn () => $h['signing']->verifyCode($token, 'view', $wrong, fsCtx()), 'otp_invalid');
    fsExpectError(fn () => $h['signing']->verifyCode($token, 'view', $wrong, fsCtx()), 'otp_locked');
    // Locked means locked: the right code no longer works either.
    fsExpectError(fn () => $h['signing']->verifyCode($token, 'view', $right, fsCtx()), 'otp_locked');

    $types = array_column($h['signing']->events(fsEnvelopeId($h)), 'type');
    expect($types)->toContain('otp.locked');
});

it('rate-limits code sends per signer', function () {
    $h = fsHarness(['otp' => ['sends_per_window' => 2]]);
    fsSent($h);
    $token = $h['mailer']->token('alice@example.com');
    $h['signing']->requestCode($token, 'view', fsCtx());
    $h['signing']->requestCode($token, 'view', fsCtx());

    fsExpectError(fn () => $h['signing']->requestCode($token, 'view', fsCtx()), 'otp_rate_limited');
});

it('never writes a code into an event', function () {
    $h = fsHarness();
    fsSent($h);
    $token = $h['mailer']->token('alice@example.com');
    $h['signing']->requestCode($token, 'view', fsCtx());
    $code = $h['mailer']->code('alice@example.com', 'view');
    $h['signing']->verifyCode($token, 'view', $code, fsCtx());

    expect(json_encode($h['signing']->events(fsEnvelopeId($h))))->not->toContain('"'.$code.'"');
});

// ---------------------------------------------------------------- order of the ceremony

it('refuses to sign without having opened the document', function () {
    $h = fsHarness();
    fsSent($h);
    $token = $h['mailer']->token('alice@example.com');
    $h['signing']->requestCode($token, 'view', fsCtx());
    $session = $h['signing']->verifyCode($token, 'view', $h['mailer']->code('alice@example.com', 'view'), fsCtx())['session'];
    $h['signing']->consent($token, $session, $h['signing']->config()->consentVersion(), fsCtx());

    $h['signing']->requestCode($token, 'accept', fsCtx(), $session);
    fsExpectError(fn () => $h['signing']->sign($token, $session, [
        'fields' => ['accept-sig' => true], 'mark' => fsMark(), 'adoption' => $h['signing']->config()->adoptionText(),
        'code' => $h['mailer']->code('alice@example.com', 'accept'),
    ], fsCtx()), 'not_viewed');
});

it('refuses the document before consent', function () {
    $h = fsHarness();
    fsSent($h);
    $token = $h['mailer']->token('alice@example.com');
    $h['signing']->requestCode($token, 'view', fsCtx());
    $session = $h['signing']->verifyCode($token, 'view', $h['mailer']->code('alice@example.com', 'view'), fsCtx())['session'];

    fsExpectError(fn () => $h['signing']->document($token, $session, fsCtx()), 'consent_required');
});

it('refuses to sign with a required field left incomplete, and keeps the accept code', function () {
    $h = fsHarness();
    fsSent($h);
    [$token, $session] = fsViewing($h);
    $h['signing']->requestCode($token, 'accept', fsCtx(), $session);
    $code = $h['mailer']->code('alice@example.com', 'accept');

    fsExpectError(fn () => $h['signing']->sign($token, $session, [
        'fields' => [], 'mark' => fsMark(), 'adoption' => $h['signing']->config()->adoptionText(), 'code' => $code,
    ], fsCtx()), 'fields_incomplete');

    // The code was not consumed by a request that failed for another reason.
    $view = $h['signing']->signerView($token, $session);
    $done = $h['signing']->sign($token, $session, [
        'fields' => array_fill_keys(array_column($view['fields'], 'id'), true), 'mark' => fsMark(),
        'adoption' => $h['signing']->config()->adoptionText(), 'code' => $code,
    ], fsCtx());
    expect($done['signer']['status'])->toBe('signed');
});

it('refuses a mark past its caps, or an image', function (array $mark) {
    $h = fsHarness();
    fsSent($h);
    [$token, $session] = fsViewing($h);
    $view = $h['signing']->signerView($token, $session);

    fsExpectError(fn () => $h['signing']->sign($token, $session, [
        'fields' => array_fill_keys(array_column($view['fields'], 'id'), true), 'mark' => $mark,
        'adoption' => $h['signing']->config()->adoptionText(), 'code' => '123456',
    ], fsCtx()), 'mark_invalid');
})->with([
    'an image' => [['kind' => 'image', 'data' => 'data:image/png;base64,AAAA']],
    'a point outside 0..1' => [['kind' => 'drawn', 'strokes' => [[[0.1, 0.1], [1.5, 0.2]]]]],
    'too many strokes' => [['kind' => 'drawn', 'strokes' => array_fill(0, 65, [[0.1, 0.1], [0.2, 0.2]])]],
    'too many points' => [['kind' => 'drawn', 'strokes' => [array_fill(0, 2049, [0.5, 0.5])]]],
    'typed too long' => [['kind' => 'typed', 'text' => str_repeat('A', 129)]],
    'typed empty' => [['kind' => 'typed', 'text' => '   ']],
]);

// ---------------------------------------------------------------- crossing envelopes

it('refuses a session from one envelope used with a link from another', function () {
    $h = fsHarness();
    fsSent($h);
    [, $sessionA] = fsViewing($h);

    // A second envelope to the same person: a different link.
    fsSent($h);
    $tokenB = $h['mailer']->token('alice@example.com');

    fsExpectError(fn () => $h['signing']->signerView($tokenB, $sessionA), 'session_required');
    fsExpectError(fn () => $h['signing']->document($tokenB, $sessionA, fsCtx()), 'session_required');
});

it('refuses one signer\'s session on another signer\'s link in the same envelope', function () {
    $h = fsHarness();
    fsSent($h, bob: true);
    [, $aliceSession] = fsViewing($h);
    $bobToken = $h['mailer']->token('bob@example.com');

    fsExpectError(fn () => $h['signing']->signerView($bobToken, $aliceSession), 'session_required');
});

it('refuses an unknown link without saying anything else', function () {
    $h = fsHarness();
    fsSent($h);

    fsExpectError(fn () => $h['signing']->requestCode(str_repeat('A', 43), 'view', fsCtx()), 'link_invalid');
});

// ---------------------------------------------------------------- reused tokens

it('refuses a link that was replaced by a reminder', function () {
    $h = fsHarness();
    $env = fsSent($h);
    $old = $h['mailer']->token('alice@example.com');
    $h['signing']->remind($env['id'], 'user:1');

    fsExpectError(fn () => $h['signing']->requestCode($old, 'view', fsCtx()), 'link_invalid');
    $h['signing']->requestCode($h['mailer']->token('alice@example.com'), 'view', fsCtx());
});

it('refuses to sign twice with the same link and session', function () {
    $h = fsHarness();
    fsSent($h, bob: true);
    [$token, $session] = fsViewing($h);
    fsSign($h, $token, $session);

    fsExpectError(fn () => fsSign($h, $token, $session), 'already_signed');
});

it('refuses an expired session', function () {
    $h = fsHarness(['session_ttl_seconds' => 60]);
    fsSent($h);
    [$token, $session] = fsViewing($h);
    $h['clock']->advance(61);

    fsExpectError(fn () => $h['signing']->signerView($token, $session), 'session_required');
});

it('closes every link once the envelope is voided', function () {
    $h = fsHarness();
    $env = fsSent($h);
    $token = $h['mailer']->token('alice@example.com');
    $h['signing']->void($env['id'], 'customer changed the order', 'user:1');

    fsExpectError(fn () => $h['signing']->requestCode($token, 'view', fsCtx()), 'envelope_not_open');
});

// ---------------------------------------------------------------- refusing what phase 1 does not do

it('refuses AdES and PAdES settings: they are later phases', function (array $settings) {
    $h = fsHarness();
    fsExpectError(fn () => $h['signing']->create(['title' => 'Q', 'document' => fsDocument(),
        'signers' => [['id' => 'alice', 'name' => 'A', 'email' => 'a@example.com']], 'settings' => $settings], 'user:1'), 'invalid_request');
})->with([
    'level ades' => [['level' => 'ades']],
    'seal pades' => [['seal' => 'pades-b-t']],
    'step-up passkey with no verifier' => [['step_up' => 'passkey']],
    'unknown per-page' => [['per_page' => 'stamp']],
]);

it('refuses a document field naming a signer the envelope does not have', function () {
    $h = fsHarness();
    fsExpectError(fn () => $h['signing']->create(['title' => 'Q', 'document' => fsDocument(),
        'signers' => [['id' => 'carol', 'name' => 'C', 'email' => 'c@example.com']]], 'user:1'), 'invalid_request');
});

function fsEnvelopeId(array $h): string
{
    return $h['signing']->listEnvelopes()[0]['id'];
}
