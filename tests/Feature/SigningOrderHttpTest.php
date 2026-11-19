<?php

declare(strict_types=1);

use FancySign\Repository\Repository;
use FancySign\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/*
 * settings.order over HTTP on the database store (CC-fancy-0051): a waiting
 * signer's link is refused as the kit error envelope, never with the PDF, and
 * the organisation default survives the database (its own column).
 */

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

it('runs a sequential envelope on the database store and refuses a waiting signer without the document', function () {
    TestApp::boot();
    $input = fsHttpEnvelope();
    $input['signers'][] = ['id' => 'bob', 'name' => 'Bob Example', 'email' => 'bob@example.com'];
    $input['settings'] = ['order' => 'sequential'];
    $id = fsHttp('POST', '/envelopes', $input)['body']['id'];
    $sent = fsHttp('POST', "/envelopes/{$id}/send")['body'];

    expect($sent['settings']['order'])->toBe('sequential')
        ->and(array_column($sent['signers'], 'status', 'id'))->toBe(['alice' => 'pending', 'bob' => 'waiting']);
    expect(fn () => TestApp::$mailer->token('bob@example.com'))->toThrow(RuntimeException::class);

    app(Repository::class)->putLink(hash('sha256', 'bob-early-token'), $id, 'bob');
    foreach ([['GET', '/s/bob-early-token/document'], ['GET', '/s/bob-early-token/envelope'], ['POST', '/s/bob-early-token/otp']] as [$method, $uri]) {
        $r = fsHttp($method, $uri, $method === 'POST' ? ['purpose' => 'view'] : null, userId: null);
        expect($r['status'])->toBe(409)
            ->and($r['body']['error'])->toBe('not_your_turn')
            ->and($r['body'])->toHaveKeys(['message', 'requestId'])
            ->and($r['type'])->toContain('application/json')
            ->and($r['raw'])->not->toContain('%PDF');
    }
});

it('stores the organisation order default in the database', function () {
    TestApp::boot();
    $signing = app(FancySign\Signing::class);

    $signing->setOrgSettings('org-1', ['order' => 'sequential', 'final_page' => 'auto'], 'user:admin');

    expect($signing->orgSettings('org-1'))->toMatchArray(['order' => 'sequential', 'final_page' => 'auto', 'per_page' => 'none'])
        ->and($signing->setOrgSettings('org-1', ['per_page' => 'initials'], 'user:admin')['order'])->toBe('sequential')
        ->and($signing->orgSettings('org-1')['order'])->toBe('sequential');
});
