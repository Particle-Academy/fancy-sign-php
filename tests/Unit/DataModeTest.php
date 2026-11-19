<?php

declare(strict_types=1);

use FancySign\Data\CanonicalDocument;
use FancySign\Data\DataCompletion;
use FancySign\Data\SignatureRecord;
use FancySign\Support\CanonicalJson;
use FancySign\Verify\Verifier;

/*
 * Data mode (CC-fancy-0052): the package signs the HOST's data and supplies
 * signing primitives, never a workflow. send() fixes the data and mails no
 * one; the host invites each signer with invite(); each signature is sealed as
 * its own record; completion is the reported fact "every signer has signed",
 * and nothing follows it. No PDF is ever made.
 */

it('creates a data-mode draft: the canonical bytes are a blob, the envelope keeps only the facts', function () {
    $h = fsHarness();
    $env = $h['signing']->create(fdmInput(), 'user:1');
    $canonical = CanonicalDocument::canonical(fdmQuote());

    expect($env['status'])->toBe('draft')
        ->and($env['settings']['mode'])->toBe('data')
        ->and($env['settings'])->not->toHaveKey('final_page')
        ->and($env['document'])->toMatchArray(['mode' => 'data', 'profile' => null, 'canonical' => 'fsig-data-1',
            'data_sha256' => FDM_QUOTE_SHA, 'data_bytes' => 272, 'as_sent_sha256' => null, 'final_sha256' => null, 'pages' => null])
        ->and($env['document']['fields_sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($h['repo']->blob("data/{$env['id']}"))->toBe($canonical)
        ->and(json_encode($h['repo']->envelope($env['id'])))->not->toContain('W-1')
        ->and(array_column($env['fields'], 'id'))->toBe(['alice-sig', 'alice-date'])
        ->and($env['fields'][0]['anchor'])->toBe(['pointer' => '/lines'])
        ->and($env['fields'][1]['anchor'])->toBeNull();
});

it('hashes the field list as canonical JSON of {id, signer_id, kind, required, label, anchor}, in order', function () {
    $env = fsHarness()['signing']->create(fdmInput(), 'user:1');
    $list = array_map(fn (array $f) => ['id' => $f['id'], 'signer_id' => $f['signer_id'], 'kind' => $f['kind'],
        'required' => $f['required'], 'label' => $f['label'], 'anchor' => $f['anchor']], $env['fields']);

    expect($env['document']['fields_sha256'])->toBe(hash('sha256', CanonicalJson::encode($list)));
});

it('send fixes the data and mails no one: no link, no email.sent', function () {
    $h = fsHarness();
    $sent = fdmSent($h, bob: true);

    expect($sent['status'])->toBe('sent')
        ->and($h['mailer']->sent)->toBe([])
        ->and(fdmTypes($h, $sent['id']))->toBe(['envelope.created', 'envelope.sent'])
        ->and(fdmData($h['signing']->events($sent['id'])[1]))->toBe(['mode' => 'data', 'data_sha256' => FDM_QUOTE_SHA,
            'data_bytes' => 272, 'fields_sha256' => $sent['document']['fields_sha256']])
        ->and(array_column($sent['signers'], 'invited_at'))->toBe([null, null])
        ->and(array_column($sent['signers'], 'status'))->toBe(['pending', 'pending']);
});

it('runs the lifecycle as primitives, with the exact event sequence, and never writes a PDF', function () {
    $h = fsHarness();
    $id = fdmSent($h, bob: true)['id'];
    fdmSignAs($h, $id, 'alice', 2);
    expect($h['signing']->envelope($id)['status'])->toBe('sent');
    fdmSignAs($h, $id, 'bob');

    $one = ['email.sent', 'link.opened', 'otp.sent', 'otp.verified', 'consent.accepted', 'document.opened', 'document.rendered', 'otp.sent', 'otp.verified'];
    expect(fdmTypes($h, $id))->toBe([
        'envelope.created', 'envelope.sent',
        ...$one, 'field.completed', 'field.completed', 'mark.adopted', 'signer.signed', 'signature.sealed',
        ...$one, 'field.completed', 'mark.adopted', 'signer.signed', 'signature.sealed',
        'envelope.completed', 'seal.applied',
    ])
        ->and($h['signing']->envelope($id)['status'])->toBe('completed')
        ->and(array_filter(fdmBlobKeys($h['repo']), fn (string $k) => ! str_starts_with($k, 'data/')))->toBe([])
        ->and(fdmLinked($h))->toBe(['alice', 'bob']);
});

it('records the data hash where pdf mode records the as-sent hash: opened, signed, completed', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    fdmSignAs($h, $id, 'alice', 3);
    $by = [];
    foreach ($h['signing']->events($id) as $e) {
        $by[$e['type']] = $e;
    }

    expect(fdmData($by['document.opened']))->toBe(['data_sha256' => FDM_QUOTE_SHA])
        ->and(fdmData($by['document.rendered']))->toBe(['data_sha256' => FDM_QUOTE_SHA, 'renderer' => 'host quote-v7 @ host-app 2026.11.19', 'pages' => 3])
        ->and($by['document.rendered']['source'])->toBe('client')
        ->and(fdmData($by['signer.signed']))->toBe(['data_sha256' => FDM_QUOTE_SHA, 'method' => 'otp', 'otp_seq' => $by['signer.signed']['seq'] - 4,
            'consent_seq' => $by['consent.accepted']['seq'], 'rendered_seq' => $by['document.rendered']['seq']])
        ->and(fdmData($by['envelope.completed']))->toBe(['data_sha256' => FDM_QUOTE_SHA]);
});

it('serves the signer the canonical bytes, exactly, with their hash', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    $token = fdmInvite($h, $id, 'alice');
    $session = fdmOpen($h, $token, 'alice');
    $doc = $h['signing']->signerDocument($token, $session, fsCtx());

    expect($doc)->toBe(['mode' => 'data', 'bytes' => CanonicalDocument::canonical(fdmQuote()), 'sha256' => FDM_QUOTE_SHA])
        ->and($h['signing']->document($token, $session, fsCtx()))->toBe($doc['bytes'])
        ->and(array_count_values(fdmTypes($h, $id))['document.opened'])->toBe(1)
        ->and($h['signing']->dataDocument($id))->toBe($doc['bytes']);
});

it('seals each signature as its own record, which verifies alone before anyone else has signed', function () {
    $h = fsHarness();
    $id = fdmSent($h, bob: true)['id'];
    $view = fdmSignAs($h, $id, 'alice', 2);
    $events = $h['signing']->events($id);
    $signatures = $h['signing']->signatures($id);
    $record = $signatures[0]['record'];
    $by = [];
    foreach ($events as $e) {
        $by[$e['type']] = $e;
    }

    expect($signatures)->toHaveCount(1)
        ->and(array_keys($record))->toBe([...SignatureRecord::KEYS, 'token', 'seal'])
        ->and($record['token'])->toStartWith('fsig_sig_v1_')
        ->and($record)->toMatchArray(['type' => 'fancy-sign.signature', 'version' => 1, 'envelope_id' => $id, 'signer_id' => 'alice',
            'name' => 'Alice Example', 'email' => 'alice@example.com', 'method' => 'otp', 'data_sha256' => FDM_QUOTE_SHA, 'data_bytes' => 272,
            'canonical' => 'fsig-data-1', 'profile' => null, 'fields' => ['alice-sig', 'alice-date'], 'per_page_pages' => null,
            'renderer' => 'host quote-v7 @ host-app 2026.11.19', 'rendered_seq' => $by['document.rendered']['seq'],
            'chain_head' => $by['signer.signed']['hash'], 'signed_at' => $by['signer.signed']['at'], 'step_up_sha256' => null,
            'key_id' => $h['keys']['key_id']])
        ->and($record['mark_sha256'])->toBe(hash('sha256', CanonicalJson::encode($signatures[0]['mark'])))
        ->and($record['consent_text_sha256'])->toBe(hash('sha256', $h['signing']->config()->consentText()))
        ->and($record['adoption_text_sha256'])->toBe(hash('sha256', $h['signing']->config()->adoptionText()))
        ->and($signatures[0]['fields_completed'])->toBe(['alice-sig', 'alice-date'])
        ->and(fdmData($by['signature.sealed']))->toBe(['signer_id' => 'alice', 'token' => $record['token'], 'key_id' => $record['key_id']])
        ->and($by['signature.sealed']['seq'])->toBe($by['signer.signed']['seq'] + 1)
        ->and($view['signer']['signature'])->toBe($record)
        ->and($h['signing']->envelope($id)['signers'][0]['signature'])->toBe($record)
        ->and($h['signing']->envelope($id)['completion'])->toBeNull();

    $verify = (new Verifier([$h['keys']['key_id'] => $h['keys']['public']]))->verifySignature(fdmQuote(), $record, $events);
    expect($verify)->toBe(['ok' => true, 'reason' => null, 'checks' => ['token' => true, 'seal' => true, 'data_hash' => true, 'chain' => true]]);
});

it('completes with a version-2 record binding every signature, which verifies with its data, events and signatures', function () {
    $h = fsHarness();
    $id = fdmSent($h, bob: true)['id'];
    fdmSignAs($h, $id, 'alice');
    fdmSignAs($h, $id, 'bob');
    $env = $h['signing']->envelope($id);
    $c = $env['completion'];
    $records = array_column($h['signing']->signatures($id), 'record');
    $events = $h['signing']->events($id);

    expect(array_keys($c))->toBe([...DataCompletion::KEYS, 'token', 'seal'])
        ->and($c)->toMatchArray(['type' => 'fancy-sign.completion', 'version' => 2, 'mode' => 'data', 'envelope_id' => $id,
            'data_sha256' => FDM_QUOTE_SHA, 'data_bytes' => 272, 'canonical' => 'fsig-data-1', 'profile' => null,
            'fields_sha256' => $env['document']['fields_sha256']])
        ->and($c['token'])->toStartWith('fsig_v2_')
        ->and(array_column($c['signers'], 'signature'))->toBe(array_column($records, 'token'))
        ->and(array_keys($c['signers'][0]))->toBe(DataCompletion::SIGNER_KEYS)
        ->and($c['chain_head'])->toBe($events[count($events) - 2]['hash'])
        ->and($env['completed_at'])->not->toBeNull()
        ->and($env['document']['final_sha256'])->toBeNull();

    $verify = (new Verifier([$h['keys']['key_id'] => $h['keys']['public']]))->verifyData(fdmQuote(), $c, $events, $records);
    expect($verify)->toBe(['ok' => true, 'reason' => null,
        'checks' => ['token' => true, 'seal' => true, 'data_hash' => true, 'signatures' => true, 'chain' => true]]);
});

it('answers previewData with the facts create() would store, side-effect free', function () {
    $h = fsHarness();
    $p = $h['signing']->previewData(fdmInput());

    expect($p)->toMatchArray(['data_sha256' => FDM_QUOTE_SHA, 'data_bytes' => 272, 'canonical' => 'fsig-data-1',
        'data' => CanonicalDocument::canonical(fdmQuote())])
        ->and(array_column($p['fields'], 'id'))->toBe(['alice-sig', 'alice-date'])
        ->and($p['fields_sha256'])->toBe($h['signing']->create(fdmInput(), 'user:1')['document']['fields_sha256'])
        ->and(fsPvRows($h['repo'])['envelopes'])->toBe(1);
    fsExpectError(fn () => $h['signing']->previewData(fsHttpEnvelope()), 'invalid_request');
});

it('lets a draft change its data, which must come with its new hash', function () {
    $h = fsHarness();
    $env = $h['signing']->create(fdmInput(), 'user:1');
    $updated = $h['signing']->update($env['id'], ['data' => '{"quote": "Q-1002"}', 'data_sha256' => CanonicalDocument::sha256('{"quote":"Q-1002"}'),
        'fields' => [['id' => 'alice-sig', 'signer' => 'alice', 'kind' => 'signature', 'anchor' => ['pointer' => '/quote']]]], 'user:1');

    expect($updated['document']['data_sha256'])->toBe(CanonicalDocument::sha256('{"quote":"Q-1002"}'))
        ->and($updated['settings']['mode'])->toBe('data')
        ->and($h['repo']->blob("data/{$env['id']}"))->toBe('{"quote":"Q-1002"}')
        ->and($h['signing']->update($env['id'], ['title' => 'Quote Q-1002'], 'user:1')['document']['data_sha256'])->toBe(CanonicalDocument::sha256('{"quote":"Q-1002"}'));
    // New data without its hash is refused: the hash is the host's proof it canonicalised the same bytes.
    fsExpectError(fn () => $h['signing']->update($env['id'], ['data' => '{"quote": "Q-1003"}'], 'user:1'), 'invalid_request');
    // Settings given without a mode keep the draft's mode.
    expect($h['signing']->update($env['id'], ['settings' => ['order' => 'sequential']], 'user:1')['settings'])
        ->toMatchArray(['mode' => 'data', 'order' => 'sequential']);
});

it('reports a sent data envelope\'s certificate as JSON with the data reference, and refuses the PDF for now', function () {
    $h = fsHarness();
    $id = fdmSent($h)['id'];
    fdmSignAs($h, $id, 'alice');
    $cert = $h['signing']->certificate($id, 'json');

    expect($cert['reference'])->toBe(['envelope' => "Envelope {$id}", 'data_sha256' => FDM_QUOTE_SHA, 'canonical' => 'fsig-data-1'])
        ->and($cert['completion']['version'])->toBe(2);
    fsExpectError(fn () => $h['signing']->certificate($id, 'pdf'), 'invalid_request');
});
