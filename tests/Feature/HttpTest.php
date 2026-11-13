<?php

declare(strict_types=1);

use FancySign\Chain\EventChain;
use FancySign\Tests\Support\TestApp;
use Illuminate\Auth\GenericUser;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/*
 * The Laravel bridge end to end: real routes, the database repository on
 * in-memory SQLite, the wire contract's shapes and error codes.
 */

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

/** @return array{status: int, body: mixed, raw: string, type: ?string} */
function fsHttp(string $method, string $uri, ?array $body = null, ?int $userId = 1, array $headers = []): array
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'TestAgent/1'];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $request = Request::create('/fancy-sign'.$uri, $method, [], [], [], $server, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $userId === null ? null : new GenericUser(['id' => $userId]));
    $response = app('router')->dispatch($request);
    $raw = (string) $response->getContent();

    return ['status' => $response->getStatusCode(), 'body' => json_decode($raw, true), 'raw' => $raw, 'type' => $response->headers->get('Content-Type')];
}

/** POST /verify as multipart: the PDF as an uploaded file, the JSON parts as fields. */
function fsVerifyUpload(string $pdf, array $fields): array
{
    $path = tempnam(sys_get_temp_dir(), 'fsv');
    file_put_contents($path, $pdf);
    $file = new Illuminate\Http\UploadedFile($path, 'final.pdf', 'application/pdf', null, true);
    $request = Request::create('/fancy-sign/verify', 'POST', $fields, [], ['pdf' => $file], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9']);
    app()->instance('request', $request);
    $response = app('router')->dispatch($request);
    @unlink($path);

    return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
}

function fsHttpEnvelope(): array
{
    return ['title' => 'Quote Q-1001', 'document' => fsDocument(), 'signers' => [['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com']]];
}

it('refuses bad input with the closed error shape', function () {
    TestApp::boot();
    $r = fsHttp('POST', '/envelopes', ['title' => '', 'document' => fsDocument(), 'signers' => []]);

    expect($r['status'])->toBe(422)
        ->and($r['body'])->toHaveKey('error')
        ->and($r['body']['error']['code'])->toBe('invalid_request');
});

it('hides another sender\'s envelope as not_found', function () {
    TestApp::boot();
    $id = fsHttp('POST', '/envelopes', fsHttpEnvelope())['body']['id'];

    $r = fsHttp('GET', "/envelopes/{$id}", userId: 2);
    expect($r['status'])->toBe(404)->and($r['body']['error']['code'])->toBe('not_found');
    expect(fsHttp('GET', '/envelopes', userId: 2)['body'])->toBe([]);
    expect(fsHttp('POST', '/envelopes', fsHttpEnvelope(), userId: null)['body']['error']['code'])->toBe('session_required');
});

it('redacts a wrong code to otp_invalid, and needs the session header for the document', function () {
    TestApp::boot();
    $id = fsHttp('POST', '/envelopes', fsHttpEnvelope())['body']['id'];
    fsHttp('POST', "/envelopes/{$id}/send");
    $token = TestApp::$mailer->token('alice@example.com');

    expect(fsHttp('POST', "/s/{$token}/otp", ['purpose' => 'view'])['status'])->toBe(202);
    $code = TestApp::$mailer->code('alice@example.com', 'view');
    $wrong = fsHttp('POST', "/s/{$token}/otp/verify", ['purpose' => 'view', 'code' => $code === '000000' ? '111111' : '000000']);
    expect($wrong['status'])->toBe(422)->and($wrong['body']['error']['code'])->toBe('otp_invalid');

    $r = fsHttp('GET', "/s/{$token}/document");
    expect($r['status'])->toBe(401)->and($r['body']['error']['code'])->toBe('session_required');
});

it('runs a whole ceremony over HTTP on the database store, and verifies the result', function () {
    TestApp::boot();
    $created = fsHttp('POST', '/envelopes', fsHttpEnvelope() + ['settings' => ['per_page' => 'initials']]);
    expect($created['status'])->toBe(201)->and($created['body']['status'])->toBe('draft');
    $id = $created['body']['id'];
    $sent = fsHttp('POST', "/envelopes/{$id}/send")['body'];
    expect($sent['status'])->toBe('sent')->and($sent['document']['pages'])->toBeGreaterThanOrEqual(2);

    $token = TestApp::$mailer->token('alice@example.com');
    fsHttp('POST', "/s/{$token}/otp", ['purpose' => 'view']);
    $session = fsHttp('POST', "/s/{$token}/otp/verify", ['purpose' => 'view', 'code' => TestApp::$mailer->code('alice@example.com', 'view')])['body']['session'];
    $h = ['X-Fancy-Sign-Session' => $session];

    $view = fsHttp('GET', "/s/{$token}/envelope", headers: $h)['body'];
    expect($view['consent']['required'])->toBeTrue();
    fsHttp('POST', "/s/{$token}/consent", ['version' => $view['consent']['version']], headers: $h);
    $pdf = fsHttp('GET', "/s/{$token}/document", headers: $h);
    expect($pdf['type'])->toBe('application/pdf')->and($pdf['raw'])->toStartWith('%PDF-');
    expect(fsHttp('POST', "/s/{$token}/events", ['type' => 'page.viewed', 'page' => 1, 'ms' => 1800], headers: $h)['status'])->toBe(202);
    expect(fsHttp('POST', "/s/{$token}/events", ['type' => 'signer.signed'], headers: $h)['body']['error']['code'])->toBe('invalid_request');

    fsHttp('POST', "/s/{$token}/otp", ['purpose' => 'accept'], headers: $h);
    $signed = fsHttp('POST', "/s/{$token}/sign", [
        'fields' => array_fill_keys(array_column($view['fields'], 'id'), true),
        'mark' => ['kind' => 'typed', 'text' => 'Alice Example'],
        'adoption' => $view['adoption_text'],
        'code' => TestApp::$mailer->code('alice@example.com', 'accept'),
    ], headers: $h);
    expect($signed['status'])->toBe(200)->and($signed['body']['signer']['status'])->toBe('signed');

    $env = fsHttp('GET', "/envelopes/{$id}")['body'];
    expect($env['status'])->toBe('completed');
    $events = fsHttp('GET', "/envelopes/{$id}/events")['body'];
    expect(EventChain::verify($events)['ok'])->toBeTrue();

    // A stored row read back through the database still verifies.
    $final = fsHttp('GET', "/envelopes/{$id}/document")['raw'];
    $verify = fsVerifyUpload($final, ['completion' => json_encode($env['completion']), 'events' => json_encode($events)]);
    expect($verify['body'])->toBe(['ok' => true, 'reason' => null, 'checks' => ['seal' => true, 'token' => true, 'final_hash' => true, 'prefix' => true, 'chain' => true]]);

    $tampered = fsVerifyUpload($final.' ', ['completion' => json_encode($env['completion'])]);
    expect($tampered['body']['reason'])->toBe('final_hash_mismatch');

    $cert = fsHttp('GET', "/envelopes/{$id}/certificate");
    expect($cert['type'])->toBe('application/pdf');
    expect(fsHttp('GET', "/envelopes/{$id}/certificate?format=json")['body']['completion']['token'])->toBe($env['completion']['token']);
});

it('caps the public verify upload', function () {
    TestApp::boot(['fancy-sign.verify_max_bytes' => 100]);
    $r = fsHttp('POST', '/verify', ['pdf' => str_repeat('x', 200), 'completion' => '{}'], userId: null);

    expect($r['status'])->toBe(429)->and($r['body']['error']['code'])->toBe('limit_exceeded');
});

it('serves the seal public key', function () {
    TestApp::boot();
    $r = fsHttp('GET', '/public-key', userId: null);

    expect($r['body'])->toBe(['key_id' => fsKeys()['key_id'], 'algorithm' => 'ed25519', 'public_key' => fsKeys()['public']]);
});

it('mails through the host mailer, and never puts the code anywhere but the mail', function () {
    TestApp::boot(fakeMailer: false);
    $id = fsHttp('POST', '/envelopes', fsHttpEnvelope())['body']['id'];
    fsHttp('POST', "/envelopes/{$id}/send");
    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect(count($messages))->toBe(1);
    $body = $messages[0]->getOriginalMessage()->getTextBody();
    preg_match('#/sign/([A-Za-z0-9_-]+)#', $body, $m);

    fsHttp('POST', "/s/{$m[1]}/otp", ['purpose' => 'view']);
    $codeMail = app('mailer')->getSymfonyTransport()->messages()[1]->getOriginalMessage()->getTextBody();
    preg_match('/is (\d{6})/', $codeMail, $c);
    expect($c[1] ?? null)->not->toBeNull();

    $stored = json_encode(app('db')->table('fancy_sign_events')->get()->all()).json_encode(app('db')->table('fancy_sign_otps')->get()->all());
    expect($stored)->not->toContain('"'.$c[1].'"')->not->toContain($m[1]);
});

it('refuses to boot the service without a seal key', function () {
    TestApp::boot(['fancy-sign.seal.secret_key' => null]);
    expect(fn () => app(FancySign\Signing::class))->toThrow(RuntimeException::class, 'FANCY_SIGN_SEAL_KEY');
});
