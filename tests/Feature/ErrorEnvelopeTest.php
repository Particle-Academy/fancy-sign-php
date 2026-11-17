<?php

declare(strict_types=1);

use FancySign\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/*
 * Every refusal is the kit-wide error envelope (CC-fancy-0031,
 * fancy-conformance suites/http/SPEC.md section 1): exactly
 * {error, message, field?, limit?, errors?, retryAfter?, requestId}, the
 * request id also in the x-request-id header, and a 422 as
 * `validation_failed` with the fancy-sign code in its one item.
 */

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

const FS_UUID4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

/** @return array{status: int, body: array<string, mixed>, header: ?string} */
function fsRaw(string $method, string $uri, ?array $body = null, ?int $userId = 1, ?string $guardRequestId = null): array
{
    $request = Request::create('/fancy-sign'.$uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9'],
        $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    if ($guardRequestId !== null) {
        $request->attributes->set('request_id', $guardRequestId);
    }
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $userId === null ? null : new Illuminate\Auth\GenericUser(['id' => $userId]));
    $response = app('router')->dispatch($request);

    return ['status' => $response->getStatusCode(), 'body' => (array) json_decode((string) $response->getContent(), true), 'header' => $response->headers->get('x-request-id')];
}

it('answers a 404 as the envelope, with a generated request id in the body and the header', function () {
    TestApp::boot();
    $r = fsRaw('GET', '/envelopes/01JD0000000000000000000000');

    expect($r['status'])->toBe(404)
        ->and(array_keys($r['body']))->toBe(['error', 'message', 'requestId'])
        ->and($r['body']['error'])->toBe('not_found')
        ->and($r['body']['message'])->toBeString()->not->toBe('')
        ->and($r['body']['requestId'])->toMatch(FS_UUID4)
        ->and($r['header'])->toBe($r['body']['requestId']);
});

it('answers a 422 as validation_failed, with the fancy-sign code as its one item', function () {
    TestApp::boot();
    $r = fsRaw('POST', '/envelopes', ['title' => '', 'document' => fsDocument(), 'signers' => []]);

    expect($r['status'])->toBe(422)
        ->and($r['body']['error'])->toBe('validation_failed')
        ->and($r['body']['errors'])->toHaveCount(1)
        ->and($r['body']['errors'][0]['error'])->toBe('invalid_request')
        ->and($r['body']['message'])->toBe($r['body']['errors'][0]['message'])
        ->and($r['body'])->not->toHaveKey('field')
        ->and(array_diff(array_keys($r['body']), ['error', 'message', 'errors', 'requestId']))->toBe([]);
});

it('keeps every non-422 code at the top level', function () {
    TestApp::boot();

    expect(fsRaw('POST', '/envelopes', fsHttpEnvelope(), userId: null)['body']['error'])->toBe('session_required');
});

it('reuses the request id fancy-http-guard put on the request', function () {
    TestApp::boot();
    $r = fsRaw('GET', '/envelopes/01JD0000000000000000000000', guardRequestId: 'req-from-guard-1');

    expect($r['body']['requestId'])->toBe('req-from-guard-1')->and($r['header'])->toBe('req-from-guard-1');
});

it('never uses the nested {error: {code}} shape', function () {
    TestApp::boot();
    $r = fsRaw('GET', '/envelopes/01JD0000000000000000000000');

    expect($r['body']['error'])->toBeString();
});
