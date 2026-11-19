<?php

declare(strict_types=1);

use FancySign\Data\CanonicalDocument;
use FancySign\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/*
 * Data mode over the Laravel bridge (CC-fancy-0052). The request path must
 * keep JSON objects as objects: Laravel's $request->only() decodes with
 * assoc: true, so {} and [] (and {"0": "a"} and ["a"]) collapse and the host's
 * hash no longer matches. The routes read `data` from the raw body instead.
 */

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

/** POST with a RAW JSON body (the data's own text), as a host's HTTP client sends it. */
function fdhPostRaw(string $method, string $uri, string $json, ?int $userId = 1): array
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'TestAgent/1'];
    $request = Illuminate\Http\Request::create('/fancy-sign'.$uri, $method, [], [], [], $server, $json);
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $userId === null ? null : new Illuminate\Auth\GenericUser(['id' => $userId]));
    $response = app('router')->dispatch($request);
    $raw = (string) $response->getContent();
    $body = json_decode($raw, true);
    $code = is_array($body) && is_string($body['error'] ?? null)
        ? ($body['error'] === 'validation_failed' ? ($body['errors'][0]['error'] ?? null) : $body['error']) : null;

    return ['status' => $response->getStatusCode(), 'body' => $body, 'code' => $code, 'raw' => $raw,
        'type' => $response->headers->get('Content-Type'), 'headers' => $response->headers];
}

/** A create body around the data's own JSON text. */
function fdhBody(string $dataText, array $fields = [['id' => 'alice-sig', 'signer' => 'alice', 'kind' => 'signature']]): string
{
    $rest = json_encode(['title' => 'Quote Q-1001', 'settings' => ['mode' => 'data'],
        'signers' => [['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com']],
        'data_sha256' => CanonicalDocument::sha256($dataText), 'fields' => $fields], JSON_THROW_ON_ERROR);

    return substr($rest, 0, -1).',"data":'.$dataText.'}';
}

it('keeps {} and [] and index-keyed objects apart on the way in (vectors d0005 and d0007)', function () {
    TestApp::boot();
    $d0005 = '{"meta": {}, "blocks": [], "cells": {"0": "a", "1": "b"}}';
    $r = fdhPostRaw('POST', '/envelopes', fdhBody($d0005));

    expect($r['status'])->toBe(201)
        ->and($r['body']['document']['data_sha256'])->toBe('12d7181d17c7f0d3c62cfd50a803e3409a22b2224ad34b780250824128b774b8');
    $r = fdhPostRaw('POST', '/envelopes', fdhBody(fdmQuote(), [['id' => 'alice-sig', 'signer' => 'alice', 'kind' => 'signature', 'anchor' => ['pointer' => '/lines/1/options']]]));
    expect($r['status'])->toBe(201)->and($r['body']['document']['data_sha256'])->toBe(FDM_QUOTE_SHA);
    $id = $r['body']['id'];

    $put = fdhPostRaw('PUT', "/envelopes/{$id}", substr(json_encode(['data_sha256' => CanonicalDocument::sha256($d0005),
        'fields' => [['id' => 'alice-sig', 'signer' => 'alice', 'kind' => 'signature', 'anchor' => ['pointer' => '/meta']]]]), 0, -1).',"data":'.$d0005.'}');
    expect($put['status'])->toBe(200)->and($put['body']['document']['data_sha256'])->toBe(CanonicalDocument::sha256($d0005));
});

it('runs a data-mode signing over HTTP: send mails no one, invite, data, rendering claim, sign, signatures, verify', function () {
    TestApp::boot();
    $id = fdhPostRaw('POST', '/envelopes', fdhBody(fdmQuote()))['body']['id'];
    expect(fsHttp('POST', "/envelopes/{$id}/send")['body']['status'])->toBe('sent')
        ->and(TestApp::$mailer->sent)->toBe([]);

    $invite = fsHttp('POST', "/envelopes/{$id}/signers/alice/invite");
    expect($invite['status'])->toBe(200)->and($invite['body']['signers'][0]['invited_at'])->not->toBeNull();
    expect(fsHttp('POST', "/envelopes/{$id}/signers/alice/invite", userId: 2)['code'])->toBe('not_found');
    $token = TestApp::$mailer->token('alice@example.com');

    fsHttp('POST', "/s/{$token}/otp", ['purpose' => 'view']);
    $session = fsHttp('POST', "/s/{$token}/otp/verify", ['purpose' => 'view', 'code' => TestApp::$mailer->code('alice@example.com', 'view')])['body']['session'];
    $hdr = ['X-Fancy-Sign-Session' => $session];
    fsHttp('POST', "/s/{$token}/consent", ['version' => '1'], headers: $hdr);
    $view = fsHttp('GET', "/s/{$token}/envelope", headers: $hdr)['body'];
    expect($view['envelope']['mode'])->toBe('data')->and($view['envelope']['data_sha256'])->toBe(FDM_QUOTE_SHA);

    $doc = fdhGet("/s/{$token}/document", $hdr);
    expect($doc['status'])->toBe(200)
        ->and($doc['type'])->toBe('application/json')
        ->and($doc['raw'])->toBe(CanonicalDocument::canonical(fdmQuote()))
        ->and($doc['headers']->get('X-Fancy-Sign-Data-Sha256'))->toBe(FDM_QUOTE_SHA);

    $claim = fsHttp('POST', "/s/{$token}/events", ['type' => 'document.rendered', 'data_sha256' => FDM_QUOTE_SHA, 'renderer' => 'host quote-v7 @ 1.0', 'pages' => 1], headers: $hdr);
    expect($claim['status'])->toBe(202);

    fsHttp('POST', "/s/{$token}/otp", ['purpose' => 'accept'], headers: $hdr);
    $signed = fsHttp('POST', "/s/{$token}/sign", ['fields' => ['alice-sig' => true], 'mark' => ['kind' => 'typed', 'text' => 'Alice Example'],
        'adoption' => FancySign\Config::DEFAULTS['adoption_text'], 'code' => TestApp::$mailer->code('alice@example.com', 'accept'), 'data_sha256' => FDM_QUOTE_SHA], headers: $hdr);
    expect($signed['status'])->toBe(200)->and($signed['body']['signer']['signature']['token'])->toStartWith('fsig_sig_v1_');

    $sigs = fsHttp('GET', "/envelopes/{$id}/signatures");
    expect($sigs['status'])->toBe(200)->and($sigs['body'][0]['record']['signer_id'])->toBe('alice')
        ->and($sigs['body'][0]['mark'])->toBe(['kind' => 'typed', 'text' => 'Alice Example'])
        ->and(fsHttp('GET', "/envelopes/{$id}/signatures", userId: 2)['code'])->toBe('not_found');

    $sender = fdhGet("/envelopes/{$id}/document", []);
    expect($sender['type'])->toBe('application/json')->and($sender['raw'])->toBe(CanonicalDocument::canonical(fdmQuote()));

    $env = fsHttp('GET', "/envelopes/{$id}")['body'];
    $events = fsHttp('GET', "/envelopes/{$id}/events")['raw'];
    $verify = fdhVerify(['completion' => json_encode($env['completion']), 'data' => fdmQuote(), 'events' => $events,
        'signatures' => json_encode(array_column($sigs['body'], 'record'))]);
    expect($verify['status'])->toBe(200)->and($verify['body']['ok'])->toBeTrue()
        ->and($verify['body']['checks'])->toBe(['token' => true, 'seal' => true, 'data_hash' => true, 'signatures' => true, 'chain' => true]);
    $bad = fdhVerify(['completion' => json_encode($env['completion']), 'data' => '{"quote": "Q-1001"}']);
    expect($bad['body']['reason'])->toBe('data_hash_mismatch');
    expect(fdhVerify(['completion' => json_encode($env['completion'])])['code'])->toBe('invalid_request');
});

/** GET returning raw bytes and headers. */
function fdhGet(string $uri, array $headers): array
{
    $server = ['HTTP_ACCEPT' => '*/*', 'REMOTE_ADDR' => '203.0.113.9'];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $request = Illuminate\Http\Request::create('/fancy-sign'.$uri, 'GET', [], [], [], $server);
    app()->instance('request', $request);
    $request->setUserResolver(fn () => new Illuminate\Auth\GenericUser(['id' => 1]));
    $response = app('router')->dispatch($request);

    return ['status' => $response->getStatusCode(), 'raw' => (string) $response->getContent(), 'type' => $response->headers->get('Content-Type'), 'headers' => $response->headers];
}

/** POST /verify as a multipart form with string fields only (data mode has no PDF). */
function fdhVerify(array $fields): array
{
    $request = Illuminate\Http\Request::create('/fancy-sign/verify', 'POST', $fields, [], [], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9']);
    app()->instance('request', $request);
    $response = app('router')->dispatch($request);
    $body = json_decode((string) $response->getContent(), true);

    return ['status' => $response->getStatusCode(), 'body' => $body,
        'code' => ($body['error'] ?? null) === 'validation_failed' ? ($body['errors'][0]['error'] ?? null) : ($body['error'] ?? null)];
}
