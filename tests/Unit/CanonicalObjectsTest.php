<?php

declare(strict_types=1);

use FancySign\Chain\EventChain;
use FancySign\Support\CanonicalJson;
use FancySign\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/*
 * Canonical JSON is defined over JSON VALUES, so an object whose keys are
 * "0", "1", ... is an object and never a list. PHP's arrays cannot tell the
 * two apart once JSON is decoded with assoc=true, so: a JSON object is held as
 * stdClass wherever it is decoded for hashing, and stdClass always encodes as
 * an object. A Node port, which keeps them apart for free, must agree.
 */

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

it('encodes an object with index-like keys as an object, and a list as a list', function () {
    expect(CanonicalJson::encode(json_decode('{"1":"b","0":"a"}')))->toBe('{"0":"a","1":"b"}')
        ->and(CanonicalJson::encode(json_decode('["a","b"]')))->toBe('["a","b"]')
        ->and(CanonicalJson::encode(['nested' => json_decode('{"0":{"0":1}}')]))->toBe('{"nested":{"0":{"0":1}}}');
});

it('encodes a PHP map whose sorted keys happen to be 0..n-1 as an object', function () {
    expect(CanonicalJson::encode([1 => 'b', 0 => 'a']))->toBe('{"0":"a","1":"b"}');
});

it('verifies a chain whose data is an object with index-like keys, read raw as a JSON string', function () {
    $event = EventChain::append(null, ['envelope_id' => 'E1', 'at' => '2026-11-18T12:00:00Z', 'type' => 'envelope.created', 'actor' => 'user:1',
        'source' => 'server', 'ip' => null, 'user_agent' => null, 'data' => json_decode('{"0":"x","1":"y"}')]);
    $raw = ['data' => '{"0":"x","1":"y"}'] + $event;
    $canonical = '{"actor":"user:1","at":"2026-11-18T12:00:00Z","data":{"0":"x","1":"y"},"envelope_id":"E1","ip":null,"seq":1,"source":"server","type":"envelope.created","user_agent":null}';

    expect($event['hash'])->toBe(hash('sha256', EventChain::GENESIS."\n".$canonical))
        ->and(EventChain::verify([$raw])['ok'])->toBeTrue();
});

it('round-trips such data through the database store and still verifies', function () {
    TestApp::boot();
    $repo = app(FancySign\Repository\Repository::class);
    $repo->saveEnvelope(['id' => 'E1', 'org_id' => null, 'status' => 'draft', 'created_by' => 'user:1', 'created_at' => '2026-11-18T12:00:00Z']);
    $repo->appendEvent('E1', fn (?array $last) => EventChain::append($last, ['envelope_id' => 'E1', 'at' => '2026-11-18T12:00:00Z', 'type' => 'envelope.created',
        'actor' => 'user:1', 'source' => 'server', 'ip' => null, 'user_agent' => null, 'data' => json_decode('{"0":"x","1":"y"}')]));

    expect(EventChain::verify($repo->events('E1'))['ok'])->toBeTrue();
});
