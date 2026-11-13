<?php

declare(strict_types=1);

use FancySign\Chain\EventChain;
use FancySign\Completion\Completion;
use FancySign\Verify\Verifier;
use FinePrint\Document;

/** Alice signs a one-signer envelope; returns the harness, the envelope and its outputs. */
function fsCompleted(array $settings = []): array
{
    $h = fsHarness();
    $env = fsSent($h, $settings);
    [$token, $session] = fsViewing($h);
    $h['signing']->pageViewed($token, $session, 1, 2400, fsCtx());
    fsSign($h, $token, $session);
    $env = $h['signing']->envelope($env['id']);

    return [$h, $env, $h['signing']->finalDocument($env['id']), $h['signing']->events($env['id'])];
}

function fsVerify(array $h, string $pdf, array $completion, ?array $events = null): array
{
    return (new Verifier([$h['keys']['key_id'] => $h['keys']['public']]))->verify($pdf, $completion, $events);
}

// ---------------------------------------------------------------- tamper evidence, failure first

it('detects a PDF changed after sealing', function () {
    [$h, $env, $pdf, $events] = fsCompleted();
    $tampered = substr_replace($pdf, 'X', intdiv(strlen($pdf), 3), 1);

    $r = fsVerify($h, $tampered, $env['completion'], $events);
    expect($r['ok'])->toBeFalse()->and($r['reason'])->toBe('final_hash_mismatch');
});

it('detects a byte changed in the as-sent part even if someone re-hashed the final file', function () {
    [$h, $env, $pdf] = fsCompleted();
    $tampered = substr_replace($pdf, 'X', 200, 1);
    $record = $env['completion'];
    $record['final_sha256'] = hash('sha256', $tampered);

    // Re-hashing breaks the token and the seal before the prefix is ever reached.
    $r = fsVerify($h, $tampered, $record);
    expect($r['ok'])->toBeFalse()->and($r['reason'])->toBe('token_mismatch');
});

it('detects a completion record whose seal does not match', function () {
    [$h, $env, $pdf] = fsCompleted();
    $record = $env['completion'];
    $record['signers'][0]['email'] = 'mallory@example.com';
    $record['token'] = Completion::tokenFor($record);

    $r = fsVerify($h, $pdf, $record);
    expect($r['ok'])->toBeFalse()->and($r['reason'])->toBe('seal_invalid');
});

it('refuses a seal by a key it was not given', function () {
    [, $env, $pdf] = fsCompleted();
    $other = sodium_crypto_sign_keypair();
    $r = (new Verifier([$env['completion']['key_id'] => base64_encode(sodium_crypto_sign_publickey($other))]))->verify($pdf, $env['completion']);

    expect($r['ok'])->toBeFalse()->and($r['reason'])->toBe('seal_invalid');
});

it('detects a tampered event chain: a changed row, a removed row, a broken link', function (callable $tamper, string $problem) {
    [$h, $env, $pdf, $events] = fsCompleted();
    $bad = $tamper($events);

    expect(EventChain::verify($bad)['problem'])->toBe($problem);
    $r = fsVerify($h, $pdf, $env['completion'], $bad);
    expect($r['ok'])->toBeFalse()->and($r['reason'])->toBe('chain_broken');
})->with([
    'changed data' => [function (array $e) { $e[3]['data'] = ['purpose' => 'accept']; return $e; }, 'hash_mismatch'],
    'removed row' => [function (array $e) { unset($e[2]); return array_values($e); }, 'sequence_gap'],
    'changed ip' => [function (array $e) { $e[1]['ip'] = '198.51.100.1'; return $e; }, 'hash_mismatch'],
    'relinked row' => [function (array $e) { $e[4]['prev_hash'] = str_repeat('0', 64); return $e; }, 'broken_link'],
]);

it('detects a chain that is valid but is not the one the record sealed', function () {
    [$h, $env, $pdf, $events] = fsCompleted();
    $short = array_slice($events, 0, 3);

    $r = fsVerify($h, $pdf, $env['completion'], $short);
    expect($r['ok'])->toBeFalse()->and($r['reason'])->toBe('chain_head_mismatch');
});

it('refuses a completion record that is not one', function (array $record) {
    [$h, , $pdf] = fsCompleted();
    $r = fsVerify($h, $pdf, $record);

    expect($r['ok'])->toBeFalse()->and($r['reason'])->toBe('completion_invalid');
})->with([
    'empty' => [[]],
    'wrong type' => [['type' => 'fancy-passkeys.login-certificate', 'version' => 1]],
    'wrong version' => [['type' => 'fancy-sign.completion', 'version' => 2]],
]);

// ---------------------------------------------------------------- the good path

it('verifies a completed envelope: seal, token, final hash, as-sent prefix and chain', function () {
    [$h, $env, $pdf, $events] = fsCompleted();

    $r = fsVerify($h, $pdf, $env['completion'], $events);
    expect($r)->toBe(['ok' => true, 'reason' => null, 'checks' => ['seal' => true, 'token' => true, 'final_hash' => true, 'prefix' => true, 'chain' => true]]);

    expect($env['status'])->toBe('completed')
        ->and($env['completion']['token'])->toStartWith('fsig_v1_')
        ->and(strlen($env['completion']['token']))->toBe(8 + 64)
        ->and($env['completion']['type'])->toBe('fancy-sign.completion')
        ->and(strlen($env['completion']['nonce']))->toBe(32)
        ->and($env['completion']['signers'][0]['method'])->toBe('otp');
});

it('keeps the as-sent PDF as a byte-for-byte prefix of the final one', function () {
    [$h, $env, $pdf] = fsCompleted();
    $asSent = $h['signing']->asSentDocument($env['id']);

    expect(substr($pdf, 0, strlen($asSent)))->toBe($asSent)
        ->and(hash('sha256', $asSent))->toBe($env['document']['as_sent_sha256'])
        ->and(hash('sha256', $pdf))->toBe($env['document']['final_sha256']);
});

it('draws the marks into the final PDF, on the fields, including the generated page', function () {
    [, $env, $pdf] = fsCompleted();
    $doc = Document::open($pdf);
    $last = $doc->pageCount();

    expect($doc->repaired())->toBeFalse()
        ->and($env['document']['pages'])->toBe($last)
        ->and($doc->text($last))->toContain('Alice Example')
        // The date-signed field is filled with the signing date.
        ->and($doc->text($last))->toContain('2026-11-13');
});

it('records every engagement event, and labels the browser-reported one', function () {
    [, , , $events] = fsCompleted();
    $types = array_column($events, 'type');

    foreach (['envelope.created', 'envelope.sent', 'email.sent', 'link.opened', 'otp.sent', 'otp.verified', 'consent.accepted',
        'document.opened', 'page.viewed', 'field.completed', 'mark.adopted', 'signer.signed', 'envelope.completed', 'seal.applied'] as $t) {
        expect($types)->toContain($t);
    }
    foreach ($events as $e) {
        expect($e['source'])->toBe($e['type'] === 'page.viewed' ? 'client' : 'server');
        expect(EventChain::TYPES)->toContain($e['type']);
    }
    expect(EventChain::verify($events)['ok'])->toBeTrue();
});

it('records a decline with its reason and ends the envelope', function () {
    $h = fsHarness();
    $env = fsSent($h);
    [$token, $session] = fsViewing($h);

    fsExpectError(fn () => $h['signing']->decline($token, $session, '  ', fsCtx()), 'invalid_request');
    $h['signing']->decline($token, $session, 'The price is wrong.', fsCtx());

    $env = $h['signing']->envelope($env['id']);
    $declined = array_values(array_filter($h['signing']->events($env['id']), fn (array $e) => $e['type'] === 'signer.declined'));
    expect($env['status'])->toBe('declined')
        ->and($env['signers'][0]['decline_reason'])->toBe('The price is wrong.')
        ->and($declined[0]['data']['reason'])->toBe('The price is wrong.');
});

it('caps browser-reported page views', function () {
    $h = fsHarness(['page_views' => ['per_page' => 2]]);
    fsSent($h);
    [$token, $session] = fsViewing($h);
    $h['signing']->pageViewed($token, $session, 1, 100, fsCtx());
    $h['signing']->pageViewed($token, $session, 1, 100, fsCtx());

    fsExpectError(fn () => $h['signing']->pageViewed($token, $session, 1, 100, fsCtx()), 'limit_exceeded');
    fsExpectError(fn () => $h['signing']->pageViewed($token, $session, 99, 100, fsCtx()), 'invalid_request');
});

it('completes only when every signer has signed, in any order', function () {
    $h = fsHarness();
    $env = fsSent($h, bob: true);
    [$bt, $bs] = fsViewing($h, 'bob@example.com');
    fsSign($h, $bt, $bs, 'bob@example.com');
    expect($h['signing']->envelope($env['id'])['status'])->toBe('sent');

    [$at, $as] = fsViewing($h);
    fsSign($h, $at, $as);
    $env = $h['signing']->envelope($env['id']);
    expect($env['status'])->toBe('completed')
        ->and(array_column($env['completion']['signers'], 'id'))->toBe(['alice', 'bob']);
});

it('puts per-page initials on the authored pages only, never on the final page', function () {
    $h = fsHarness();
    $env = fsSent($h, ['per_page' => 'initials']);
    $final = $env['document']['pages'];
    $every = array_values(array_filter($env['fields'], fn (array $f) => ($f['anchor']['every_page'] ?? false) === true));

    expect($every)->toHaveCount(1);
    foreach ($every[0]['placement'] as $p) {
        expect($p['page'])->toBeLessThan($final);
    }
});

it('renders a certificate of completion as PDF and JSON', function () {
    [$h, $env] = fsCompleted();
    $json = $h['signing']->certificate($env['id'], 'json');
    $pdf = $h['signing']->certificate($env['id'], 'pdf');

    expect($json['completion']['token'])->toBe($env['completion']['token'])
        ->and($json['events'])->not->toBe([]);
    $text = implode("\n", array_map(fn (int $p) => Document::open($pdf)->text($p), range(1, Document::open($pdf)->pageCount())));
    expect($text)->toContain('Certificate of completion')
        ->toContain($env['completion']['token'])
        ->toContain('reported by the signer')
        ->toContain('203.0.113.7');
});

it('signs the same completion bytes for the same inputs (deterministic seal)', function () {
    $keys = fsKeys();
    $record = ['type' => 'fancy-sign.completion', 'version' => 1, 'envelope_id' => 'E1', 'as_sent_sha256' => str_repeat('a', 64),
        'as_sent_bytes' => 10, 'final_sha256' => str_repeat('b', 64), 'signers' => [], 'chain_head' => str_repeat('c', 64),
        'nonce' => str_repeat('d', 32), 'key_id' => $keys['key_id']];

    expect(Completion::seal($record, $keys['secret']))->toBe(Completion::seal($record, $keys['secret']));
});
