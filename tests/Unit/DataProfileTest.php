<?php

declare(strict_types=1);

use FancySign\Data\CanonicalDocument;

/*
 * The optional typed profile `data_profile: "last-word"` (CC-fancy-0052
 * section 1.4): the ONLY place fancy-sign looks inside the data, and only to
 * refuse an image that is not inlined, because the signed hash would cover its
 * URL and not its pixels. Opaque data (no profile) is never inspected.
 */

function fdpDoc(string $src, bool $nested = false): string
{
    $image = ['type' => 'image', 'src' => $src, 'alt' => 'Logo'];
    $blocks = $nested
        ? [['type' => 'paragraph', 'runs' => [['text' => 'Hi']]], ['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'quote', 'blocks' => [$image]]]]]]]]]
        : [['type' => 'paragraph', 'runs' => [['text' => 'Hi']]], $image];

    return json_encode(['title' => 'Quote', 'blocks' => $blocks], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function fdpInput(string $doc, ?string $profile, string $pointer = '/blocks'): array
{
    return fdmInput(['data' => $doc, 'data_sha256' => CanonicalDocument::sha256($doc), 'data_profile' => $profile,
        'fields' => [['id' => 'sig', 'signer' => 'alice', 'kind' => 'signature', 'anchor' => ['pointer' => $pointer]]]]);
}

it('accepts opaque data with an image-shaped object and an http src untouched', function () {
    $env = fsHarness()['signing']->create(fdpInput(fdpDoc('https://cdn.example.com/logo.png'), null), 'user:1');

    expect($env['document']['profile'])->toBeNull()->and($env['status'])->toBe('draft');
});

it('under last-word, refuses a non-inlined image with data_external_reference, naming the pointer', function () {
    $h = fsHarness();
    try {
        $h['signing']->create(fdpInput(fdpDoc('https://cdn.example.com/logo.png'), 'last-word'), 'user:1');
        $this->fail('no refusal');
    } catch (FancySign\SignError $e) {
        expect($e->errorCode)->toBe('data_external_reference')
            ->and($e->getMessage())->toStartWith('/blocks/1/src: an image must be inlined as a data: URI');
    }
    try {
        $h['signing']->create(fdpInput(fdpDoc('logo.png', nested: true), 'last-word'), 'user:1');
        $this->fail('no refusal');
    } catch (FancySign\SignError $e) {
        expect($e->errorCode)->toBe('data_external_reference')
            ->and($e->getMessage())->toStartWith('/blocks/1/rows/0/cells/0/blocks/0/blocks/0/src:');
    }
    expect(fsPvRows($h['repo'])['envelopes'])->toBe(0);
});

it('under last-word, accepts an inlined data: image, and records the profile but keeps it out of the hash', function () {
    $h = fsHarness();
    $doc = fdpDoc('data:image/png;base64,iVBORw0KGgo=');
    $id = fdmSent($h, ['data' => $doc, 'data_sha256' => CanonicalDocument::sha256($doc), 'data_profile' => 'last-word',
        'fields' => [['id' => 'alice-sig', 'signer' => 'alice', 'kind' => 'signature']]])['id'];
    $env = $h['signing']->envelope($id);

    expect($env['document']['profile'])->toBe('last-word')
        ->and($env['document']['data_sha256'])->toBe(CanonicalDocument::sha256($doc));

    $token = fdmInvite($h, $id, 'alice');
    $session = fdmOpen($h, $token, 'alice');
    fdmSign($h, $token, $session, 'alice', ['data_sha256' => CanonicalDocument::sha256($doc)]);
    expect($h['signing']->signatures($id)[0]['record']['profile'])->toBe('last-word')
        ->and($h['signing']->envelope($id)['completion']['profile'])->toBe('last-word');
});

it('refuses an unknown profile', function () {
    expect(fsHarness()['signing']->create(fdpInput(fdpDoc('data:x'), 'last-word'), 'user:1')['document']['profile'])->toBe('last-word');
    fsExpectError(fn () => fsHarness()['signing']->create(fdpInput(fdpDoc('data:x'), 'docx'), 'user:1'), 'invalid_request');
});
