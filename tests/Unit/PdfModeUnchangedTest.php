<?php

declare(strict_types=1);

/*
 * pdf mode is unchanged by data mode (CC-fancy-0052 section 8.1). `mode`
 * omitted and `mode: "pdf"` give identical PDFs and identical chains (up to
 * the random envelope id). The conformance goldens (DriftTest, every sign/*
 * row), SigningOrderTest's 0.5.0 chain and FinalPageTest's 0.4.0 page must
 * stay green with no golden moved. A cross-version check (the 0.6.0 release
 * against this code, same machine) is recorded in the CHANGELOG.
 */

/** A pdf scenario: send, Alice and Bob sign. Its chain with the envelope id normalised, and the final PDF's text. */
function fpuRun(array $settings): array
{
    $h = fsHarness();
    $env = $h['signing']->create(['title' => 'Quote Q-1001', 'document' => fsDocument(), 'signers' => [
        ['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com'],
        ['id' => 'bob', 'name' => 'Bob Example', 'email' => 'bob@example.com'],
    ], 'settings' => $settings], 'user:1');
    $id = $env['id'];
    $h['signing']->send($id, 'user:1');
    foreach (['alice', 'bob'] as $who) {
        [$token, $session] = fsViewing($h, "{$who}@example.com");
        fsSign($h, $token, $session, "{$who}@example.com");
    }
    // The PDFs print the envelope id, so their hashes (and the seal token over them) differ per run.
    $chain = array_map(fn (array $e) => [$e['seq'], $e['type'], $e['actor'], $e['source'],
        array_intersect_key(['as_sent_sha256' => 'H', 'final_sha256' => 'H', 'token' => 'T'], fdmData($e)) + fdmData($e)], $h['signing']->events($id));

    return ['chain' => $chain, 'text' => str_replace($id, 'ID', implode("\f", fsPvText($h['signing']->finalDocument($id)))),
        'pages' => $h['signing']->envelope($id)['document']['pages']];
}

it('gives byte-identical preview PDFs and identical as-sent models with mode omitted and mode pdf', function () {
    $h = fsHarness();
    foreach ([[], ['per_page' => 'initials'], ['final_page' => 'auto'], ['order' => 'sequential']] as $settings) {
        $input = fsHttpEnvelope() + ['settings' => $settings];
        $pdf = $input;
        $pdf['settings']['mode'] = 'pdf';

        expect(hash('sha256', $h['signing']->preview($pdf)))->toBe(hash('sha256', $h['signing']->preview($input)))
            ->and($h['signing']->previewDocument($pdf))->toBe($h['signing']->previewDocument($input));
    }
});

it('gives identical chains and final PDFs with mode omitted and mode pdf', function () {
    expect(fpuRun(['mode' => 'pdf']))->toBe(fpuRun([]));
});

it('stores mode pdf by default and keeps the pdf events exactly', function () {
    $h = fsHarness();
    $sent = fsSent($h);
    $events = $h['signing']->events($sent['id']);

    expect($sent['settings']['mode'])->toBe('pdf')
        ->and($sent['document']['mode'])->toBe('pdf')
        ->and($sent['document']['data_sha256'])->toBeNull()
        ->and(array_keys(fdmData($events[1])))->toBe(['as_sent_sha256', 'pages'])
        ->and(fdmData($events[2]))->toBe(['signer_id' => 'alice']);
});
