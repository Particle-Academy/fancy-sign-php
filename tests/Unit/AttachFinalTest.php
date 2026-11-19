<?php

declare(strict_types=1);

use FancySign\Data\FinalRecord;
use FancySign\Signing;
use FancySign\Tests\Support\FakeMailer;
use FancySign\Tests\Support\FrozenClock;
use FancySign\Tests\Support\StaleEnvelopeRepository;
use FancySign\Verify\Verifier;
use FinePrint\Document;

/*
 * attachFinal (CC-fancy-0052 section 5.3, owner Q3): after completion the host
 * may register the hash of the final PDF it made from the data. The package
 * never reads or stores the PDF. Once, and idempotent: the same hash again
 * returns the stored record with no new event; a different hash is refused.
 */

/** A completed one-signer data envelope. */
function fafCompleted(?array $h = null): array
{
    $h ??= fsHarness();
    $id = fdmSent($h)['id'];
    fdmSignAs($h, $id, 'alice');

    return [$h, $id];
}

const FAF_PDF = "%PDF-1.7\n% the host's final quote, drawn by the host\n%%EOF\n";

it('refuses before completion, and in pdf mode', function () {
    $h = fsHarness();
    $draft = $h['signing']->create(fdmInput(bob: true), 'user:1');
    fsExpectError(fn () => $h['signing']->attachFinal($draft['id'], hash('sha256', FAF_PDF), strlen(FAF_PDF), 'user:1'), 'envelope_not_completed');
    $h['signing']->send($draft['id'], 'user:1');
    fdmSignAs($h, $draft['id'], 'alice');
    fsExpectError(fn () => $h['signing']->attachFinal($draft['id'], hash('sha256', FAF_PDF), strlen(FAF_PDF), 'user:1'), 'envelope_not_completed');
    expect(fdmTypes($h, $draft['id']))->not->toContain('document.finalized');

    $h = fsHarness();
    $env = fsSent($h);
    fsExpectError(fn () => $h['signing']->attachFinal($env['id'], hash('sha256', FAF_PDF), strlen(FAF_PDF), 'user:1'), 'invalid_request');
});

it('refuses a malformed hash or length', function (string $sha, int $bytes) {
    [$h, $id] = fafCompleted();
    fsExpectError(fn () => $h['signing']->attachFinal($id, $sha, $bytes, 'user:1'), 'invalid_request');
    expect(fdmTypes($h, $id))->not->toContain('document.finalized');
})->with([
    'uppercase hex' => [strtoupper(hash('sha256', 'x')), 10],
    'short' => [substr(hash('sha256', 'x'), 1), 10],
    'not hex' => [str_repeat('z', 64), 10],
    'zero bytes' => [hash('sha256', 'x'), 0],
    'negative bytes' => [hash('sha256', 'x'), -1],
]);

it('registers the final PDF hash once: document.finalized, then a sealed fancy-sign.final record', function () {
    [$h, $id] = fafCompleted();
    $h['clock']->advance(60);
    $sha = hash('sha256', FAF_PDF);
    $final = $h['signing']->attachFinal($id, $sha, strlen(FAF_PDF), 'user:1');
    $env = $h['signing']->envelope($id);
    $events = $h['signing']->events($id);
    $last = $events[count($events) - 1];

    expect($last['type'])->toBe('document.finalized')
        ->and($last['actor'])->toBe('user:1')
        ->and($last['source'])->toBe('server')
        ->and(fdmData($last))->toBe(['final_sha256' => $sha, 'final_bytes' => strlen(FAF_PDF)])
        ->and(array_keys($final))->toBe([...FinalRecord::KEYS, 'token', 'seal'])
        ->and($final)->toMatchArray(['type' => 'fancy-sign.final', 'version' => 1, 'envelope_id' => $id,
            'completion_token' => $env['completion']['token'], 'final_sha256' => $sha, 'final_bytes' => strlen(FAF_PDF),
            'attached_at' => $last['at'], 'attached_by' => 'user:1', 'chain_head' => $last['hash'], 'key_id' => $h['keys']['key_id']])
        ->and($final['token'])->toStartWith('fsig_fin_v1_')
        ->and($final['token'])->toBe(FinalRecord::tokenFor($final))
        ->and(FinalRecord::wellFormed($final))->toBeTrue()
        ->and($env['final'])->toBe($final)
        // The package stores no document: still only the data blob.
        ->and(array_filter(fdmBlobKeys($h['repo']), fn (string $k) => ! str_starts_with($k, 'data/')))->toBe([])
        ->and($env['status'])->toBe('completed');
});

it('is idempotent: the same hash and length return the stored record with no new event', function () {
    [$h, $id] = fafCompleted();
    $sha = hash('sha256', FAF_PDF);
    $first = $h['signing']->attachFinal($id, $sha, strlen(FAF_PDF), 'user:1');
    $count = count($h['signing']->events($id));
    $h['clock']->advance(3600);
    $again = $h['signing']->attachFinal($id, $sha, strlen(FAF_PDF), 'user:2');

    expect($again)->toBe($first)
        ->and(count($h['signing']->events($id)))->toBe($count)
        ->and(array_count_values(fdmTypes($h, $id))['document.finalized'])->toBe(1);
});

it('refuses a different hash, or the same hash with a different length: final_already_attached', function () {
    [$h, $id] = fafCompleted();
    $first = $h['signing']->attachFinal($id, hash('sha256', FAF_PDF), strlen(FAF_PDF), 'user:1');
    $count = count($h['signing']->events($id));

    fsExpectError(fn () => $h['signing']->attachFinal($id, hash('sha256', FAF_PDF.'x'), strlen(FAF_PDF) + 1, 'user:1'), 'final_already_attached');
    fsExpectError(fn () => $h['signing']->attachFinal($id, hash('sha256', FAF_PDF), strlen(FAF_PDF) + 1, 'user:1'), 'final_already_attached');
    expect(count($h['signing']->events($id)))->toBe($count)
        ->and($h['signing']->envelope($id)['final'])->toBe($first);
});

it('decides "already attached" from the chain under the append lock, not from an envelope read earlier', function () {
    // A store whose envelope reads are STALE (a second request that read the
    // envelope before the first one attached): only the chain, read inside
    // appendEvent, can refuse the second attach.
    $repo = new StaleEnvelopeRepository();
    $keys = fsKeys();
    $mailer = new FakeMailer();
    $h = ['signing' => new Signing(repo: $repo, mailer: $mailer, clock: new FrozenClock(), sealSecretKey: $keys['secret'],
        appKey: str_repeat('k', 32), linkUrl: fn (string $t): string => 'https://app.example.test/sign/'.$t),
        'mailer' => $mailer, 'repo' => $repo, 'keys' => $keys];
    [, $id] = fafCompleted($h);
    $repo->stale = $repo->envelope($id);
    $h['signing']->attachFinal($id, hash('sha256', FAF_PDF), strlen(FAF_PDF), 'user:1');

    fsExpectError(fn () => $h['signing']->attachFinal($id, hash('sha256', 'another pdf'), 11, 'user:1'), 'final_already_attached');
    expect(array_count_values(array_column($repo->events($id), 'type'))['document.finalized'])->toBe(1);
});

it('names the attached final in the certificate, JSON and PDF', function () {
    [$h, $id] = fafCompleted();
    $before = $h['signing']->certificate($id, 'json');
    expect($before['final'])->toBeNull();

    $final = $h['signing']->attachFinal($id, hash('sha256', FAF_PDF), strlen(FAF_PDF), 'user:1');
    $json = $h['signing']->certificate($id, 'json');
    $pdf = $h['signing']->certificate($id, 'pdf');
    $doc = Document::open($pdf);
    $text = implode("\n", array_map(fn (int $p) => $doc->text($p), range(1, $doc->pageCount())));
    $flat = preg_replace('/\s+/', ' ', $text);

    expect($json['final'])->toBe($final)
        ->and($json['envelope']['final'])->toBe($final)
        ->and($flat)->toContain('Certificate of completion')
        ->toContain(FDM_QUOTE_SHA)
        ->toContain($final['final_sha256'])
        ->toContain($final['token'])
        ->toContain($json['completion']['token'])
        ->toContain($json['envelope']['signers'][0]['signature']['token'])
        ->toContain('host quote-v7 @ host-app 2026.11.19')
        ->toContain('reported by the signer');
});

it('draws a data-mode certificate PDF with no final attached, and before completion', function () {
    $h = fsHarness();
    $id = fdmSent($h, bob: true)['id'];
    fdmSignAs($h, $id, 'alice');
    $doc = Document::open($h['signing']->certificate($id, 'pdf'));
    $flat = preg_replace('/\s+/', ' ', implode("\n", array_map(fn (int $p) => $doc->text($p), range(1, $doc->pageCount()))));

    expect($flat)->toContain(FDM_QUOTE_SHA)
        ->toContain('No final document has been registered')
        ->not->toContain('As-sent document SHA-256');
});

// ---------------------------------------------------------------- verifier

/** A completed envelope with a final attached, and what a verifier needs. */
function fafVerifiable(): array
{
    [$h, $id] = fafCompleted();
    $final = $h['signing']->attachFinal($id, hash('sha256', FAF_PDF), strlen(FAF_PDF), 'user:1');

    return ['h' => $h, 'id' => $id, 'final' => $final, 'completion' => $h['signing']->envelope($id)['completion'],
        'events' => $h['signing']->events($id), 'records' => array_column($h['signing']->signatures($id), 'record'),
        'v' => new Verifier([$h['keys']['key_id'] => $h['keys']['public']])];
}

it('verifies the final PDF against its record, with and without events', function () {
    $c = fafVerifiable();
    $full = $c['v']->verifyData(fdmQuote(), $c['completion'], $c['events'], $c['records'], FAF_PDF, $c['final']);

    expect($full)->toBe(['ok' => true, 'reason' => null,
        'checks' => ['token' => true, 'seal' => true, 'data_hash' => true, 'signatures' => true, 'chain' => true, 'final' => true]])
        ->and($c['v']->verifyData(fdmQuote(), $c['completion'], null, null, FAF_PDF, $c['final'])['ok'])->toBeTrue()
        // A final record alone (no PDF) still proves it is genuine and of this completion.
        ->and($c['v']->verifyData(fdmQuote(), $c['completion'], null, null, null, $c['final'])['checks']['final'])->toBeTrue()
        ->and($c['v']->verifyData(fdmQuote(), $c['completion'])['checks']['final'])->toBeNull();
});

it('names each final failure', function () {
    $c = fafVerifiable();
    $secret = $c['h']['keys']['secret'];
    $reason = fn (?string $pdf, mixed $final, ?array $events = null) => $c['v']->verifyData(fdmQuote(), $c['completion'], $events, null, $pdf, $final)['reason'];
    $reseal = function (array $record) use ($secret): array {
        unset($record['token'], $record['seal']);
        $record['token'] = FinalRecord::tokenFor($record);
        $record['seal'] = FinalRecord::seal($record, $secret);

        return $record;
    };

    // Malformed, tampered, or sealed by an unknown key.
    expect($reason(FAF_PDF, ['extra' => 1] + $c['final']))->toBe('final_invalid')
        ->and($reason(FAF_PDF, ['version' => 2] + $c['final']))->toBe('final_invalid')
        ->and($reason(FAF_PDF, ['final_bytes' => 1] + $c['final']))->toBe('final_invalid')
        ->and($reason(FAF_PDF, ['seal' => base64_encode(str_repeat("\0", 64))] + $c['final']))->toBe('final_invalid')
        ->and($reason(FAF_PDF, ['key_id' => 'unknown'] + $reseal($c['final'])))->toBe('final_invalid')
        // A PDF with no record to check it against.
        ->and($reason(FAF_PDF, null))->toBe('final_invalid')
        // The PDF is not the registered one.
        ->and($reason(FAF_PDF.' ', $c['final']))->toBe('final_hash_mismatch')
        ->and($reason('%PDF-1.7 another', $c['final']))->toBe('final_hash_mismatch')
        // A genuine record of ANOTHER completion.
        ->and($reason(FAF_PDF, $reseal(['completion_token' => 'fsig_v2_'.str_repeat('0', 64)] + $c['final'])))->toBe('final_not_of_completion')
        ->and($reason(FAF_PDF, $reseal(['envelope_id' => '01JD9999999999999999999999'] + $c['final'])))->toBe('final_not_of_completion')
        // With events: its chain_head must be the document.finalized that registered this hash.
        ->and($reason(FAF_PDF, $reseal(['chain_head' => $c['completion']['chain_head']] + $c['final']), $c['events']))->toBe('final_invalid')
        ->and($reason(FAF_PDF, $c['final'], array_slice($c['events'], 0, -1)))->toBe('final_invalid');
});

it('reports the final failure only after the completion, data, signatures and chain pass', function () {
    $c = fafVerifiable();
    $r = $c['v']->verifyData('{"quote": "other"}', $c['completion'], null, null, FAF_PDF.'x', $c['final']);

    expect($r['reason'])->toBe('data_hash_mismatch')->and($r['checks']['final'])->toBeNull();
});
