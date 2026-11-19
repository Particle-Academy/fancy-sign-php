<?php

declare(strict_types=1);

use FancySign\Data\DataCompletion;
use FancySign\Data\SignatureRecord;
use FancySign\Verify\Verifier;

/*
 * Verifier::verifyData() and verifySignature() (CC-fancy-0052 section 5.1):
 * every named reason, one sabotage each, stopping at the first failure.
 */

/** A completed two-signer data envelope: its harness, id, completion, records and events. */
function fdvCompleted(): array
{
    $h = fsHarness();
    $id = fdmSent($h, bob: true)['id'];
    fdmSignAs($h, $id, 'alice');
    fdmSignAs($h, $id, 'bob');

    return ['h' => $h, 'id' => $id, 'completion' => $h['signing']->envelope($id)['completion'],
        'records' => array_column($h['signing']->signatures($id), 'record'), 'events' => $h['signing']->events($id)];
}

function fdvVerifier(array $h): Verifier
{
    return new Verifier([$h['keys']['key_id'] => $h['keys']['public']]);
}

/** Re-seal a record with the test key after changing it: what only the key holder can do. */
function fdvReseal(array $record, string $secret, string $class = SignatureRecord::class): array
{
    unset($record['token'], $record['seal']);
    $record['token'] = $class::tokenFor($record);
    $record['seal'] = $class::seal($record, $secret);

    return $record;
}

it('verifies a non-canonical export of the same data: the verifier canonicalises', function () {
    $c = fdvCompleted();
    $pretty = json_encode(json_decode(fdmQuote(), false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    expect(fdvVerifier($c['h'])->verifyData($pretty, $c['completion'], $c['events'], $c['records'])['ok'])->toBeTrue()
        ->and(fdvVerifier($c['h'])->verifyData(fdmQuote(), $c['completion'])['checks'])
        ->toBe(['token' => true, 'seal' => true, 'data_hash' => true, 'signatures' => null, 'chain' => null, 'final' => null]);
});

it('names each verifyData failure, in order', function () {
    $c = fdvCompleted();
    $v = fdvVerifier($c['h']);
    $secret = $c['h']['keys']['secret'];
    $reason = fn (...$args) => $v->verifyData(...$args)['reason'];

    expect($reason(fdmQuote(), ['version' => 1] + $c['completion']))->toBe('completion_invalid')
        ->and($reason(fdmQuote(), ['extra' => 1] + $c['completion']))->toBe('completion_invalid')
        ->and($reason(fdmQuote(), ['title' => 'x'] + $c['completion']))->toBe('completion_invalid');

    $changed = $c['completion'];
    $changed['signers'][0]['name'] = 'Mallory';
    expect($reason(fdmQuote(), $changed))->toBe('token_mismatch');
    $changed['token'] = DataCompletion::tokenFor($changed);
    expect($reason(fdmQuote(), $changed))->toBe('seal_invalid')
        ->and((new Verifier([]))->verifyData(fdmQuote(), $c['completion'])['reason'])->toBe('seal_invalid');

    expect($reason('{not json', $c['completion']))->toBe('data_invalid')
        ->and($reason('[1]', $c['completion']))->toBe('data_invalid')
        ->and($reason('{"quote": "Q-1001"}', $c['completion']))->toBe('data_hash_mismatch');

    $badRecord = $c['records'][0];
    $badRecord['signer_id'] = 'bob';
    expect($reason(fdmQuote(), $c['completion'], null, [$badRecord]))->toBe('signature_invalid')
        ->and($reason(fdmQuote(), $c['completion'], null, [['type' => 'x']]))->toBe('signature_invalid')
        ->and($reason(fdmQuote(), $c['completion'], null, [fdvReseal(['fields' => []] + $c['records'][0], $secret)]))->toBe('signature_invalid')
        ->and($reason(fdmQuote(), $c['completion'], null, [fdvReseal(['data_bytes' => 1] + $c['records'][0], $secret)]))->toBe('signature_invalid');

    $tampered = $c['events'];
    $tampered[3]['data'] = ['signer_id' => 'mallory'];
    expect($reason(fdmQuote(), $c['completion'], $tampered))->toBe('chain_broken')
        ->and($reason(fdmQuote(), $c['completion'], array_slice($c['events'], 0, 10)))->toBe('chain_head_mismatch');

    // Only the seal key's holder can make a record the chain never saw. The verifier still says so.
    $forged = fdvReseal(['chain_head' => str_repeat('c', 64)] + $c['records'][0], $secret);
    $completion = $c['completion'];
    $completion['signers'][0]['signature'] = $forged['token'];
    $completion = fdvReseal($completion, $secret, DataCompletion::class);
    expect($reason(fdmQuote(), $completion, $c['events'], [$forged]))->toBe('signature_not_in_chain')
        ->and($reason(fdmQuote(), $completion, $c['events']))->toBe('signature_not_in_chain');
});

it('reports each check as true, false, or null when not reached', function () {
    $c = fdvCompleted();
    $r = fdvVerifier($c['h'])->verifyData('{"quote": "Q-1001"}', $c['completion'], $c['events'], $c['records']);

    expect($r)->toBe(['ok' => false, 'reason' => 'data_hash_mismatch',
        'checks' => ['token' => true, 'seal' => true, 'data_hash' => false, 'signatures' => null, 'chain' => null, 'final' => null]]);
});

it('verifies one signature with no completion, and names each verifySignature failure', function () {
    $h = fsHarness();
    $id = fdmSent($h, bob: true)['id'];
    $before = $h['signing']->events($id);
    fdmSignAs($h, $id, 'alice');
    $record = $h['signing']->signatures($id)[0]['record'];
    $events = $h['signing']->events($id);
    $v = fdvVerifier($h);
    $reason = fn (...$args) => $v->verifySignature(...$args)['reason'];

    expect($v->verifySignature(fdmQuote(), $record, $events)['ok'])->toBeTrue()
        ->and($v->verifySignature(fdmQuote(), $record)['checks'])->toBe(['token' => true, 'seal' => true, 'data_hash' => true, 'chain' => null])
        ->and($reason(fdmQuote(), ['extra' => 1] + $record))->toBe('signature_invalid')
        ->and($reason(fdmQuote(), ['name' => 'Mallory'] + $record))->toBe('token_mismatch');
    $retoken = ['name' => 'Mallory'] + $record;
    $retoken['token'] = SignatureRecord::tokenFor($retoken);
    expect($reason(fdmQuote(), $retoken))->toBe('seal_invalid')
        ->and($reason('nope', $record))->toBe('data_invalid')
        ->and($reason('{}', $record))->toBe('data_hash_mismatch');
    $tampered = $events;
    $tampered[1]['actor'] = 'user:2';
    expect($reason(fdmQuote(), $record, $tampered))->toBe('chain_broken')
        ->and($reason(fdmQuote(), $record, fdsOtherEnvelope($events)))->toBe('chain_broken')
        ->and($reason(fdmQuote(), $record, $before))->toBe('signature_not_in_chain');
});

/** The same rows claiming another envelope id (hashes no longer match, and the id differs). */
function fdsOtherEnvelope(array $events): array
{
    return array_map(fn (array $e) => ['envelope_id' => '01JD9999999999999999999999'] + $e, $events);
}
