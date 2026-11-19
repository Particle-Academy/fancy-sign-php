<?php

declare(strict_types=1);

use FancySign\Pdf\Renderer;
use FinePrint\Agent;
use FinePrint\Document;

/*
 * Signing::preview (CC-fancy-0047): the as-sent PDF exactly as send() would
 * render it for this input and organisation, with no envelope, no event and no
 * mail. The envelope reference and the content hash are not known before send,
 * so those two lines carry Renderer::PREVIEW_PLACEHOLDER in the same place.
 *
 * Parity is checked on the decoded page content streams, which hold every text
 * position and every field box (`re`), and on the extracted text: the preview
 * and the real as-sent PDF must agree on all of it apart from the operands of
 * the two placeholder lines.
 */

/** A quote long enough to run onto a second authored page, so per-page marks land more than once. */
function fsPvInput(array $settings = []): array
{
    $doc = fsDocument();
    for ($i = 1; $i <= 70; $i++) {
        $doc['blocks'][] = ['type' => 'paragraph', 'runs' => [['text' => "Line item {$i}: one widget at 10.00."]]];
    }
    $doc['blocks'][] = ['type' => 'dateSignedField', 'id' => 'accept-date', 'signer' => 'alice'];

    return ['title' => 'Quote Q-1001', 'document' => $doc, 'settings' => $settings, 'signers' => [
        ['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com'],
        ['id' => 'bob', 'name' => 'Bob Example', 'email' => 'bob@example.com'],
    ]];
}

/**
 * Each page's decoded content stream, with the TJ operand of the reference and
 * content-hash lines replaced by a marker: everything else (every Tm position,
 * every field box, every other string) stays as written.
 *
 * @return list<string>
 */
function fsPvStreams(string $pdf): array
{
    $doc = Document::open($pdf);
    $out = [];
    for ($p = 1; $p <= $doc->pageCount(); $p++) {
        $out[] = (string) preg_replace_callback('/^\[(.*)\] TJ$/m', function (array $m): string {
            preg_match_all('/<([0-9A-Fa-f]*)>/', $m[1], $hex);
            $text = implode('', array_map('hex2bin', $hex[1]));

            return str_starts_with($text, 'Reference: ') || str_starts_with($text, 'Document content SHA-256: ') ? '[<PLACEHOLDER-LINE>] TJ' : $m[0];
        }, $doc->pageContents($p));
    }

    return $out;
}

/** Preview, then create and send the same input in the same store; both PDFs and the sent envelope. */
function fsPvBoth(array $input, ?string $org = null, ?callable $before = null): array
{
    $h = fsPvHarness();
    if ($before !== null) {
        $before($h);
    }
    $preview = $h['signing']->preview($input, $org);
    $sent = $h['signing']->send($h['signing']->create($input, 'user:1', $org)['id'], 'user:1');

    return [$preview, $h['signing']->asSentDocument($sent['id']), $sent];
}

function fsPvAssertParity(string $preview, string $asSent, array $sent): void
{
    expect(Agent::pageCount($preview))->toBe(Agent::pageCount($asSent))
        ->and(Agent::pageCount($preview))->toBe($sent['document']['pages']);

    // Every position, every field box and every other string, page by page.
    $p = fsPvStreams($preview);
    $s = fsPvStreams($asSent);
    expect($p)->toBe($s);
    // The two placeholder lines were really there, in both, on the signature page.
    expect(substr_count(end($p), '[<PLACEHOLDER-LINE>] TJ'))->toBe(2)
        ->and(substr_count(end($s), '[<PLACEHOLDER-LINE>] TJ'))->toBe(2);

    // The text, identical apart from the two placeholder lines.
    $placeholder = Renderer::PREVIEW_PLACEHOLDER;
    $expected = array_map(fn (string $t) => str_replace(
        ['Reference: Envelope '.$sent['id'], 'Document content SHA-256: '.Renderer::modelHash(fsPvInput($sent['settings'])['document'])],
        ['Reference: '.$placeholder, 'Document content SHA-256: '.$placeholder],
        $t,
    ), fsPvText($asSent));
    $previewText = fsPvText($preview);
    expect($previewText)->toBe($expected)
        ->and(end($previewText))->toContain('Reference: '.$placeholder)
        ->and(end($previewText))->toContain('Document content SHA-256: '.$placeholder)
        ->and(implode("\n", $previewText))->not->toContain($sent['id']);
}

it('has an unmistakable placeholder', function () {
    expect(Renderer::PREVIEW_PLACEHOLDER)->toStartWith('PREVIEW')
        ->and(Renderer::PREVIEW_PLACEHOLDER)->toContain('when sent');
});

it('renders exactly what send() renders, apart from the two placeholder lines (per_page none)', function () {
    [$preview, $asSent, $sent] = fsPvBoth(fsPvInput());

    expect($sent['settings']['per_page'])->toBe('none')
        ->and($sent['document']['pages'])->toBeGreaterThanOrEqual(3);   // two authored pages and the signature page
    fsPvAssertParity($preview, $asSent, $sent);
    expect(fsPvText($preview)[0])->not->toContain("\nAE");
});

it('renders the per-page initials send() would place, on every authored page (per_page initials)', function () {
    [$preview, $asSent, $sent] = fsPvBoth(fsPvInput(['per_page' => 'initials']));

    $everyPage = array_values(array_filter($sent['fields'], fn (array $f) => $f['id'] === 'every-page-initials-alice'));
    expect($everyPage[0]['placement'])->toHaveCount($sent['document']['pages'] - 1);
    fsPvAssertParity($preview, $asSent, $sent);
    foreach (array_slice(fsPvText($preview), 0, -1) as $page) {
        expect($page)->toContain('AE')->and($page)->toContain('BE');
    }
});

it('renders under the organisation\'s per_page setting, as send() does', function () {
    $orgOn = fn (array $h) => $h['signing']->setOrgSettings('org-1', ['per_page' => 'signature'], 'user:admin');
    [$preview, $asSent, $sent] = fsPvBoth(fsPvInput(), 'org-1', $orgOn);

    expect($sent['settings']['per_page'])->toBe('signature');
    fsPvAssertParity($preview, $asSent, $sent);

    // And differs from the same input with no organisation: the org setting is really applied.
    $h = fsPvHarness();
    $orgOn($h);
    expect(fsPvStreams($h['signing']->preview(fsPvInput(), 'org-1')))->not->toBe(fsPvStreams($h['signing']->preview(fsPvInput())));
});

it('writes no row, tells no event and sends no mail', function () {
    $h = fsPvHarness();
    $h['signing']->setOrgSettings('org-1', ['per_page' => 'initials'], 'user:admin');
    $before = fsPvRows($h['repo']);

    $pdf = $h['signing']->preview(fsPvInput(), 'org-1');
    $pdf2 = $h['signing']->preview(fsPvInput(['per_page' => 'signature']));

    expect($pdf)->toStartWith('%PDF-')->and($pdf2)->toStartWith('%PDF-')
        ->and(fsPvRows($h['repo']))->toBe($before)
        ->and($h['signing']->listEnvelopes())->toBe([])
        ->and($h['told']->events)->toBe([])
        ->and($h['mailer']->sent)->toBe([]);

    // The instruments are live: the same harness records a real send's rows, events and mail.
    $h['signing']->send($h['signing']->create(fsPvInput(), 'user:1')['id'], 'user:1');
    expect(fsPvRows($h['repo'])['envelopes'])->toBe(1)
        ->and(fsPvRows($h['repo'])['blobs'])->toBe(1)
        ->and($h['told']->events)->not->toBe([])
        ->and($h['mailer']->sent)->toHaveCount(2);
});

it('refuses invalid input exactly as create() does', function (array $input, ?string $org = null) {
    $h = fsPvHarness();
    $h['signing']->setOrgSettings('org-1', ['per_page' => 'signature'], 'user:admin');
    $before = fsPvRows($h['repo']);

    $create = null;
    try {
        $h['signing']->create($input, 'user:1', $org);
    } catch (FancySign\SignError $e) {
        $create = $e;
    }
    expect($create)->not->toBeNull();
    $before = fsPvRows($h['repo']);

    try {
        $h['signing']->preview($input, $org);
        throw new RuntimeException('preview accepted input create() refused');
    } catch (FancySign\SignError $e) {
        expect($e->errorCode)->toBe($create->errorCode)
            ->and($e->getMessage())->toBe($create->getMessage());
    }
    expect(fsPvRows($h['repo']))->toBe($before);
})->with([
    'no title' => [['title' => '', 'document' => fsDocument(), 'signers' => [['id' => 'alice', 'name' => 'A', 'email' => 'alice@example.com']]]],
    'no document' => [['title' => 'Q', 'signers' => [['id' => 'alice', 'name' => 'A', 'email' => 'alice@example.com']]]],
    'invalid document' => [['title' => 'Q', 'document' => ['blocks' => [['type' => 'nope']]], 'signers' => [['id' => 'alice', 'name' => 'A', 'email' => 'alice@example.com']]]],
    'no signers' => [['title' => 'Q', 'document' => fsDocument(), 'signers' => []]],
    'bad email' => [['title' => 'Q', 'document' => fsDocument(), 'signers' => [['id' => 'alice', 'name' => 'A', 'email' => 'not-an-email']]]],
    'field for a missing signer' => [['title' => 'Q', 'document' => fsDocument(), 'signers' => [['id' => 'bob', 'name' => 'B', 'email' => 'bob@example.com']]]],
    'bad per_page' => [['title' => 'Q', 'document' => fsDocument(), 'signers' => [['id' => 'alice', 'name' => 'A', 'email' => 'alice@example.com']], 'settings' => ['per_page' => 'stamp']]],
    'passkey with no verifier' => [['title' => 'Q', 'document' => fsDocument(), 'signers' => [['id' => 'alice', 'name' => 'A', 'email' => 'alice@example.com']], 'settings' => ['step_up' => 'passkey']]],
    'per_page against the organisation' => [['title' => 'Q', 'document' => fsDocument(), 'signers' => [['id' => 'alice', 'name' => 'A', 'email' => 'alice@example.com']], 'settings' => ['per_page' => 'none']], 'org-1'],
]);
