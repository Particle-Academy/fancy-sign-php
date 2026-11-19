<?php

declare(strict_types=1);

use FancySign\Config;
use FancySign\Events\EnvelopeEventRecorded;
use FancySign\Listener\SignListener;
use FancySign\Repository\InMemoryRepository;
use FancySign\Seal\Ed25519;
use FancySign\Signing;
use FancySign\Support\Context;
use FancySign\Tests\Support\FakeMailer;
use FancySign\Tests\Support\FrozenClock;
use FinePrint\Agent;

/**
 * One wired Signing service with an in-memory store, a fake inbox and a frozen
 * clock. `fsHarness()` returns all four so a test can act as sender, signer and
 * time.
 *
 * @return array{signing: Signing, mailer: FakeMailer, clock: FrozenClock, repo: InMemoryRepository, keys: array{secret: string, public: string, key_id: string}}
 */
function fsHarness(array $config = []): array
{
    $keys = fsKeys();
    $mailer = new FakeMailer();
    $clock = new FrozenClock();
    $repo = new InMemoryRepository();
    $signing = new Signing(
        repo: $repo,
        mailer: $mailer,
        clock: $clock,
        config: new Config($config),
        sealSecretKey: $keys['secret'],
        appKey: str_repeat('k', 32),
        linkUrl: fn (string $token): string => 'https://app.example.test/sign/'.$token,
    );

    return ['signing' => $signing, 'mailer' => $mailer, 'clock' => $clock, 'repo' => $repo, 'keys' => $keys];
}

/** A fixed seal key, so results are reproducible across runs. */
function fsKeys(): array
{
    $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x07", 32));

    return [
        'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        'key_id' => Ed25519::keyId(base64_encode(sodium_crypto_sign_publickey($pair))),
    ];
}

function fsCtx(string $ip = '203.0.113.7'): Context
{
    return new Context($ip, 'Mozilla/5.0 (test)');
}

/** A quote with a signature field and an initials field for the first signer. */
function fsDocument(): array
{
    return ['title' => 'Quote Q-1001', 'blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Quote Q-1001']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Three widgets at 10.00 each.']]],
        ['type' => 'signatureField', 'id' => 'accept-sig', 'signer' => 'alice'],
        ['type' => 'initialsField', 'id' => 'accept-ini', 'signer' => 'alice', 'required' => false],
    ]];
}

/** Create an envelope for Alice (and optionally Bob) and send it. */
function fsSent(array $h, array $settings = [], bool $bob = false): array
{
    $signers = [['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com']];
    if ($bob) {
        $signers[] = ['id' => 'bob', 'name' => 'Bob Example', 'email' => 'bob@example.com'];
    }
    $env = $h['signing']->create(['title' => 'Quote Q-1001', 'document' => fsDocument(), 'signers' => $signers, 'settings' => $settings], 'user:1');

    return $h['signing']->send($env['id'], 'user:1');
}

/** Take a signer from link to an open session with consent given and the document opened. */
function fsViewing(array $h, string $email = 'alice@example.com'): array
{
    $token = $h['mailer']->token($email);
    $h['signing']->requestCode($token, 'view', fsCtx());
    $session = $h['signing']->verifyCode($token, 'view', $h['mailer']->code($email, 'view'), fsCtx())['session'];
    $h['signing']->consent($token, $session, $h['signing']->config()->consentVersion(), fsCtx());
    $h['signing']->document($token, $session, fsCtx());

    return [$token, $session];
}

function fsMark(): array
{
    return ['kind' => 'drawn', 'strokes' => [[[0.1, 0.5], [0.3, 0.2], [0.5, 0.7], [0.9, 0.4]]]];
}

/** Sign as `$email`: fields completed, mark adopted, fresh accept code. */
function fsSign(array $h, string $token, string $session, string $email = 'alice@example.com'): array
{
    $view = $h['signing']->signerView($token, $session);
    $fields = [];
    foreach ($view['fields'] as $f) {
        $fields[$f['id']] = true;
    }
    $h['signing']->requestCode($token, 'accept', fsCtx(), $session);

    return $h['signing']->sign($token, $session, [
        'fields' => $fields,
        'mark' => fsMark(),
        'adoption' => $h['signing']->config()->adoptionText(),
        'code' => $h['mailer']->code($email, 'accept'),
    ], fsCtx());
}

/** Expect a SignError with this wire code. */
function fsExpectError(callable $fn, string $code): void
{
    try {
        $fn();
    } catch (FancySign\SignError $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    throw new RuntimeException("expected SignError {$code}, nothing was thrown");
}

// ---- the Laravel bridge over HTTP, shared by every Feature test (moved from HttpTest)

/** @return array{status: int, body: mixed, raw: string, type: ?string} */
function fsHttp(string $method, string $uri, ?array $body = null, ?int $userId = 1, array $headers = []): array
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'TestAgent/1'];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $request = \Illuminate\Http\Request::create('/fancy-sign'.$uri, $method, [], [], [], $server, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $userId === null ? null : new \Illuminate\Auth\GenericUser(['id' => $userId]));
    $response = app('router')->dispatch($request);
    $raw = (string) $response->getContent();

    $body = json_decode($raw, true);
    // The fancy-sign code of a refusal: a 422 carries it as its one item (ErrorEnvelopeTest pins the shape).
    $code = is_array($body) && is_string($body['error'] ?? null)
        ? ($body['error'] === 'validation_failed' ? ($body['errors'][0]['error'] ?? null) : $body['error'])
        : null;

    return ['status' => $response->getStatusCode(), 'body' => $body, 'code' => $code, 'raw' => $raw, 'type' => $response->headers->get('Content-Type')];
}

/** POST /verify as multipart: the PDF as an uploaded file, the JSON parts as fields. */
function fsVerifyUpload(string $pdf, array $fields): array
{
    $path = tempnam(sys_get_temp_dir(), 'fsv');
    file_put_contents($path, $pdf);
    $file = new Illuminate\Http\UploadedFile($path, 'final.pdf', 'application/pdf', null, true);
    $request = \Illuminate\Http\Request::create('/fancy-sign/verify', 'POST', $fields, [], ['pdf' => $file], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9']);
    app()->instance('request', $request);
    $response = app('router')->dispatch($request);
    @unlink($path);

    return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
}

function fsHttpEnvelope(): array
{
    return ['title' => 'Quote Q-1001', 'document' => fsDocument(), 'signers' => [['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com']]];
}

// ---- shared by the preview tests (PreviewTest, FinalPageTest)

/** A harness whose host hook records every event it is told about. */
function fsPvHarness(array $config = []): array
{
    $keys = fsKeys();
    $mailer = new FakeMailer();
    $repo = new InMemoryRepository();
    $told = new class implements SignListener
    {
        /** @var list<EnvelopeEventRecorded> */
        public array $events = [];

        public function recorded(EnvelopeEventRecorded $event): void
        {
            $this->events[] = $event;
        }
    };
    $signing = new Signing(
        repo: $repo,
        mailer: $mailer,
        sealSecretKey: $keys['secret'],
        appKey: str_repeat('k', 32),
        linkUrl: fn (string $token): string => 'https://app.example.test/sign/'.$token,
        config: new Config($config),
        clock: new FrozenClock(),
        listener: $told,
    );

    return ['signing' => $signing, 'mailer' => $mailer, 'repo' => $repo, 'told' => $told];
}

/** Every stored row of the in-memory store, by table. */
function fsPvRows(InMemoryRepository $repo): array
{
    $counts = [];
    foreach ((new ReflectionClass($repo))->getProperties() as $p) {
        $v = $p->getValue($repo);
        $counts[$p->getName()] = $p->getName() === 'events' ? array_sum(array_map('count', $v)) : count($v);
    }

    return $counts;
}

/** @return list<string> each page's extracted text */
function fsPvText(string $pdf): array
{
    $read = Agent::read($pdf);
    expect($read['ok'])->toBeTrue();

    return array_map(fn (array $p) => $p['text'], $read['pages']);
}

// ---- data mode (CC-fancy-0052), shared by the Data* tests

/** Plan vector d0007's sha256: the fsig-data-1 hash of fdmQuote(). */
const FDM_QUOTE_SHA = '9f3a6327d5c426994325ae9037518f595c604153944915a21236aa728e9ed2a9';

/** Plan vector d0007: opaque quote data, as the host's own JSON text (not canonical). */
function fdmQuote(): string
{
    $rows = json_decode((string) file_get_contents(__DIR__.'/Conformance/fsig-data-1-vectors.json'), true, 8, JSON_THROW_ON_ERROR);

    return (string) array_column($rows, 'input', 'id')['d0007'];
}

/** A data-mode create() input: Alice (and Bob) sign the quote. `$over` replaces top-level keys. */
function fdmInput(array $over = [], bool $bob = false): array
{
    $signers = [['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com']];
    $fields = [
        ['id' => 'alice-sig', 'signer' => 'alice', 'kind' => 'signature', 'anchor' => ['pointer' => '/lines'], 'label' => 'Buyer'],
        ['id' => 'alice-date', 'signer' => 'alice', 'kind' => 'date_signed'],
    ];
    if ($bob) {
        $signers[] = ['id' => 'bob', 'name' => 'Bob Example', 'email' => 'bob@example.com'];
        $fields[] = ['id' => 'bob-sig', 'signer' => 'bob', 'kind' => 'signature'];
    }

    return array_replace(['title' => 'Quote Q-1001', 'settings' => ['mode' => 'data'], 'signers' => $signers,
        'data' => fdmQuote(), 'data_sha256' => FDM_QUOTE_SHA, 'fields' => $fields], $over);
}

/** Create and send a data-mode envelope. Nobody is invited yet. */
function fdmSent(array $h, array $over = [], bool $bob = false, ?string $org = null): array
{
    $env = $h['signing']->create(fdmInput($over, $bob), 'user:1', $org);

    return $h['signing']->send($env['id'], 'user:1');
}

/** Invite one signer (the host's call), and return their link token. */
function fdmInvite(array $h, string $id, string $who): string
{
    $h['signing']->invite($id, $who, 'user:1');

    return $h['mailer']->token("{$who}@example.com");
}

/** From the link to an open session: view code, consent, the data fetched. */
function fdmOpen(array $h, string $token, string $who): string
{
    $h['signing']->requestCode($token, 'view', fsCtx());
    $session = $h['signing']->verifyCode($token, 'view', $h['mailer']->code("{$who}@example.com", 'view'), fsCtx())['session'];
    $h['signing']->consent($token, $session, $h['signing']->config()->consentVersion(), fsCtx());
    $h['signing']->document($token, $session, fsCtx());

    return $session;
}

/** The browser's rendering claim. */
function fdmRender(array $h, string $token, string $session, int $pages = 1, string $renderer = 'host quote-v7 @ host-app 2026.11.19'): void
{
    $h['signing']->documentRendered($token, $session, ['data_sha256' => FDM_QUOTE_SHA, 'renderer' => $renderer, 'pages' => $pages], fsCtx());
}

/** Sign every field of `$who` with a fresh accept code and the data hash. `$extra` overrides the input. */
function fdmSign(array $h, string $token, string $session, string $who, array $extra = []): array
{
    $fields = [];
    foreach ($h['signing']->signerView($token, $session)['fields'] as $f) {
        $fields[$f['id']] = true;
    }
    $h['signing']->requestCode($token, 'accept', fsCtx(), $session);

    return $h['signing']->sign($token, $session, $extra + [
        'fields' => $fields, 'mark' => fsMark(), 'adoption' => $h['signing']->config()->adoptionText(),
        'code' => $h['mailer']->code("{$who}@example.com", 'accept'), 'data_sha256' => FDM_QUOTE_SHA,
    ], fsCtx());
}

/** Invite, open, render and sign, as one signer. */
function fdmSignAs(array $h, string $id, string $who, int $pages = 1): array
{
    $token = fdmInvite($h, $id, $who);
    $session = fdmOpen($h, $token, $who);
    fdmRender($h, $token, $session, $pages);

    return fdmSign($h, $token, $session, $who);
}

/** @return list<string> the envelope's event types, in order */
function fdmTypes(array $h, string $id): array
{
    return array_column($h['signing']->events($id), 'type');
}

/** @return list<string> who has been mailed a link, in order */
function fdmLinked(array $h): array
{
    return array_values(array_map(fn (array $m) => strstr($m['to'], '@', true), array_filter($h['mailer']->sent, fn (array $m) => $m['kind'] === 'link')));
}

/** An event's data as an array, whether the store kept it as an array or a stdClass. */
function fdmData(array $event): array
{
    return json_decode(json_encode($event['data'], JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR) ?? [];
}

/** The in-memory store's blob keys. */
function fdmBlobKeys(InMemoryRepository $repo): array
{
    return array_keys((new ReflectionProperty($repo, 'blobs'))->getValue($repo));
}
