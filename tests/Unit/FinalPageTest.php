<?php

declare(strict_types=1);

use FancySign\Pdf\Renderer;
use FancySign\Verify\Verifier;
use FinePrint\Agent;
use FinePrint\Writer\Signable;

/*
 * settings.final_page (CC-fancy-0048): where the generated signature section
 * goes, and Signing::previewDocument, the as-sent document MODEL.
 *
 *   always  the generated page after the content, as 0.4.0 did, byte for byte
 *   auto    nothing when every signer already has a required signature and
 *           date field; otherwise the section flows after the content, on the
 *           same page when it fits, moved whole to a new page when it does not
 *   never   nothing, and an envelope with a signer who has no signature field
 *           is refused
 *
 * With no generated section the envelope reference and the content hash are a
 * fixed footer line on the last page.
 */

function fsFpSigners(): array
{
    return [
        ['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com'],
        ['id' => 'bob', 'name' => 'Bob Example', 'email' => 'bob@example.com'],
    ];
}

/** A quote of `$lines` line items, then the given field nodes. */
function fsFpDoc(int $lines = 0, array $fields = []): array
{
    $blocks = [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Quote Q-1001']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Three widgets at 10.00 each.']]],
    ];
    for ($i = 1; $i <= $lines; $i++) {
        $blocks[] = ['type' => 'paragraph', 'runs' => [['text' => "Line item {$i}: one widget at 10.00."]]];
    }

    return ['title' => 'Quote Q-1001', 'blocks' => array_merge($blocks, $fields)];
}

/** A required signature and date field for every signer: the quote already carries its own signature block. */
function fsFpOwnFields(): array
{
    return [
        ['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice'],
        ['type' => 'dateSignedField', 'id' => 'date-alice', 'signer' => 'alice'],
        ['type' => 'signatureField', 'id' => 'sig-bob', 'signer' => 'bob'],
        ['type' => 'dateSignedField', 'id' => 'date-bob', 'signer' => 'bob'],
    ];
}

function fsFpInput(array $doc, array $settings = []): array
{
    return ['title' => 'Quote Q-1001', 'document' => $doc, 'signers' => fsFpSigners(), 'settings' => $settings];
}

/** Create and send; the sent envelope and its as-sent PDF. */
function fsFpSend(array $h, array $input, ?string $org = null): array
{
    $sent = $h['signing']->send($h['signing']->create($input, 'user:1', $org)['id'], 'user:1');

    return [$sent, $h['signing']->asSentDocument($sent['id'])];
}

/** @return array<string, array<string, mixed>> fields by id */
function fsFpFields(array $env): array
{
    return array_column($env['fields'], null, 'id');
}

function fsFpGenerated(array $env): array
{
    return array_values(array_filter($env['fields'], fn (array $f) => str_starts_with($f['id'], 'final-')));
}

/** What 0.4.0's Renderer::asSent passed to fine-print, verbatim. */
function fsFp040(array $doc, array $signers, string $reference, string $hash, string $perPage = 'none'): string
{
    $options = ['signaturePage' => [
        'title' => 'Signatures',
        'reference' => $reference,
        'contentHash' => $hash,
        'signers' => array_map(fn (array $s) => ['id' => $s['id'], 'name' => $s['name'], 'email' => $s['email']], $signers),
    ]];
    if ($perPage !== 'none') {
        $options['pageMarks'] = ['kind' => $perPage, 'signers' => array_map(fn (array $s) => ['id' => $s['id'], 'label' => Renderer::initials($s['name'])], $signers)];
    }

    return Signable::write($doc, $options)['bytes'];
}

/**
 * The number of line items at which the content and the section's HEADING
 * still fit on page 1 but the whole section does not: the case keep-together
 * exists for. Measured by fine-print itself, so it holds whatever the fonts.
 */
function fsFpSplitLines(): int
{
    $section = [
        ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Signatures']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Reference: x', 'size' => 8]]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Document content SHA-256: x', 'size' => 8]]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Bob Example', 'bold' => true]], 'spaceBefore' => 14],
        ['type' => 'signatureField', 'id' => 'final-signature-bob', 'signer' => 'bob', 'label' => 'Signature'],
        ['type' => 'dateSignedField', 'id' => 'final-date-bob', 'signer' => 'bob', 'label' => 'Date signed'],
    ];
    $alice = [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice'], ['type' => 'dateSignedField', 'id' => 'date-alice', 'signer' => 'alice']];
    for ($n = 60; $n >= 1; $n--) {
        $content = fsFpDoc($n, $alice);
        if (Agent::pageCount(Signable::write($content)['bytes']) !== 1) {
            continue;
        }
        $headingOnly = $content;
        $headingOnly['blocks'][] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Signatures']], 'keepNext' => false];
        $all = $content;
        array_push($all['blocks'], ...$section);
        if (Agent::pageCount(Signable::write($headingOnly)['bytes']) === 1 && Agent::pageCount(Signable::write($all)['bytes']) === 2) {
            return $n;
        }
    }
    throw new RuntimeException('no line count splits the section');
}

// ------------------------------------------------------------------ always

it('defaults final_page to always and renders byte for byte what 0.4.0 rendered', function (string $perPage) {
    $h = fsPvHarness();
    $doc = fsFpDoc(70, [['type' => 'signatureField', 'id' => 'accept-sig', 'signer' => 'alice']]);
    $input = fsFpInput($doc, ['per_page' => $perPage]);

    [$sent, $asSent] = fsFpSend($h, $input);

    expect($sent['settings']['final_page'])->toBe('always')
        ->and(fsFpFields($sent)['final-signature-alice']['anchor'])->toBe(['final_page' => true])
        ->and(fsFpFields($sent)['final-date-bob']['anchor'])->toBe(['final_page' => true])
        ->and($asSent)->toBe(fsFp040($doc, fsFpSigners(), 'Envelope '.$sent['id'], Renderer::modelHash($doc), $perPage))
        ->and($h['signing']->preview($input))->toBe(fsFp040($doc, fsFpSigners(), Renderer::PREVIEW_PLACEHOLDER, Renderer::PREVIEW_PLACEHOLDER, $perPage));

    // Saying it explicitly changes nothing.
    expect($h['signing']->preview(fsFpInput($doc, ['per_page' => $perPage, 'final_page' => 'always'])))->toBe($h['signing']->preview($input));
})->with(['none', 'initials', 'signature']);

it('validates final_page like every other envelope setting', function (mixed $value) {
    $h = fsPvHarness();
    $before = fsPvRows($h['repo']);

    fsExpectError(fn () => $h['signing']->create(fsFpInput(fsFpDoc(), ['final_page' => $value]), 'user:1'), 'invalid_request');
    fsExpectError(fn () => $h['signing']->preview(fsFpInput(fsFpDoc(), ['final_page' => $value])), 'invalid_request');
    fsExpectError(fn () => $h['signing']->previewDocument(fsFpInput(fsFpDoc(), ['final_page' => $value])), 'invalid_request');
    expect(fsPvRows($h['repo']))->toBe($before);
})->with(['sometimes', 'ALWAYS', '', 1, true, [['auto']]]);

// ------------------------------------------------------------------ auto

it('auto: flows the section onto the last content page when it fits', function () {
    $h = fsPvHarness();
    $doc = fsFpDoc(0, [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice']]);

    [$sent, $pdf] = fsFpSend($h, fsFpInput($doc, ['final_page' => 'auto']));
    $text = fsPvText($pdf);

    expect($sent['settings']['final_page'])->toBe('auto')
        ->and($sent['document']['pages'])->toBe(1)
        ->and(Agent::pageCount($pdf))->toBe(1)
        ->and(fsFpGenerated($sent))->toHaveCount(4)
        ->and(fsFpFields($sent)['final-signature-bob']['anchor'])->toBe(['signature_section' => true])
        ->and(fsFpFields($sent)['final-signature-bob']['placement'][0]['page'])->toBe(1)
        ->and(fsFpFields($sent)['final-date-alice']['placement'][0]['page'])->toBe(1)
        ->and($text[0])->toContain('Three widgets')
        ->and($text[0])->toContain('Signatures')
        ->and($text[0])->toContain('Reference: Envelope '.$sent['id'])
        ->and($text[0])->toContain('Document content SHA-256: '.Renderer::modelHash($doc));
    // The same input under always is two pages: the flow really saved one.
    expect(Agent::pageCount($h['signing']->preview(fsFpInput($doc))))->toBe(2);
});

it('auto: keeps the section together with its heading, moved whole to a new page when it does not fit', function (string $perPage) {
    $h = fsPvHarness();
    $n = fsFpSplitLines();
    $doc = fsFpDoc($n, [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice'], ['type' => 'dateSignedField', 'id' => 'date-alice', 'signer' => 'alice']]);

    [$sent, $pdf] = fsFpSend($h, fsFpInput($doc, ['final_page' => 'auto', 'per_page' => $perPage]));
    $text = fsPvText($pdf);

    expect($sent['settings']['final_page'])->toBe('auto')
        ->and(Agent::pageCount($pdf))->toBe(2)
        ->and($text[0])->toContain('Line item '.$n)
        ->and($text[0])->not->toContain('Signatures')
        ->and($text[0])->not->toContain('Reference:')
        ->and($text[1])->toContain('Signatures')
        ->and($text[1])->toContain('Reference: Envelope '.$sent['id'])
        ->and($text[1])->toContain('Document content SHA-256: '.Renderer::modelHash($doc))
        ->and($text[1])->toContain('Bob Example');
    foreach (fsFpGenerated($sent) as $f) {
        expect(array_column($f['placement'], 'page'))->toBe([2]);
    }
    if ($perPage !== 'none') {
        // Moved to a page of its own, it is the generated page: no per-page mark on it.
        expect(array_column(fsFpFields($sent)["every-page-{$perPage}-bob"]['placement'], 'page'))->toBe([1]);
    }
})->with(['none', 'initials']);

it('auto: appends nothing when every signer already has a required signature and date field', function () {
    $h = fsPvHarness();
    $doc = fsFpDoc(70, fsFpOwnFields());

    [$sent, $pdf] = fsFpSend($h, fsFpInput($doc, ['final_page' => 'auto', 'per_page' => 'initials']));
    $text = fsPvText($pdf);
    $contentPages = Agent::pageCount(Signable::write($doc)['bytes']);

    expect(fsFpGenerated($sent))->toBe([])
        ->and($contentPages)->toBeGreaterThanOrEqual(2)
        ->and($sent['document']['pages'])->toBe($contentPages)
        ->and(implode("\n", $text))->not->toContain('Signatures')
        ->and(array_column(fsFpFields($sent)['every-page-initials-alice']['placement'], 'page'))->toBe(range(1, $contentPages));
    // The reference and the content hash: a fixed footer line on the LAST page, and only there.
    expect(end($text))->toContain('Reference: Envelope '.$sent['id'])
        ->and(end($text))->toContain('Document content SHA-256: '.Renderer::modelHash($doc))
        ->and($text[0])->not->toContain('Reference:');
});

it('auto: still generates the section when a signer lacks a required signature or date field', function (array $fields) {
    $h = fsPvHarness();

    [$sent] = fsFpSend($h, fsFpInput(fsFpDoc(0, $fields), ['final_page' => 'auto']));

    expect(fsFpGenerated($sent))->toHaveCount(4)
        ->and(array_unique(array_map(fn (array $f) => json_encode($f['anchor']), fsFpGenerated($sent))))->toBe(['{"signature_section":true}']);
})->with([
    'bob has no date field' => [array_slice(fsFpOwnFields(), 0, 3)],
    'bob\'s signature is optional' => [[...array_slice(fsFpOwnFields(), 0, 2),
        ['type' => 'signatureField', 'id' => 'sig-bob', 'signer' => 'bob', 'required' => false],
        ['type' => 'dateSignedField', 'id' => 'date-bob', 'signer' => 'bob']]],
    'bob\'s date is optional' => [[...array_slice(fsFpOwnFields(), 0, 3),
        ['type' => 'dateSignedField', 'id' => 'date-bob', 'signer' => 'bob', 'required' => false]]],
]);

// ------------------------------------------------------------------ never

it('never: refuses an envelope with a signer who has no required signature field', function (array $fields) {
    $h = fsPvHarness();
    $before = fsPvRows($h['repo']);
    $input = fsFpInput(fsFpDoc(0, $fields), ['final_page' => 'never']);

    foreach ([
        fn () => $h['signing']->create($input, 'user:1'),
        fn () => $h['signing']->preview($input),
        fn () => $h['signing']->previewDocument($input),
    ] as $call) {
        try {
            $call();
            throw new RuntimeException('accepted');
        } catch (FancySign\SignError $e) {
            expect($e->errorCode)->toBe('invalid_request')
                ->and($e->getMessage())->toContain('final_page')
                ->and($e->getMessage())->toContain('bob');
        }
    }
    expect(fsPvRows($h['repo']))->toBe($before);
})->with([
    'bob has no field' => [[['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice']]],
    'bob\'s signature is optional' => [[['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice'], ['type' => 'signatureField', 'id' => 'sig-bob', 'signer' => 'bob', 'required' => false]]],
    'bob has only a date' => [[['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice'], ['type' => 'dateSignedField', 'id' => 'date-bob', 'signer' => 'bob']]],
]);

it('never: appends nothing and puts the reference in the last page\'s footer', function () {
    $h = fsPvHarness();
    $doc = fsFpDoc(0, [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice'], ['type' => 'signatureField', 'id' => 'sig-bob', 'signer' => 'bob']]);

    [$sent, $pdf] = fsFpSend($h, fsFpInput($doc, ['final_page' => 'never']));
    $text = fsPvText($pdf);

    expect($sent['settings']['final_page'])->toBe('never')
        ->and(fsFpGenerated($sent))->toBe([])
        ->and(Agent::pageCount($pdf))->toBe(1)
        ->and($text[0])->not->toContain('Signatures')
        ->and($text[0])->toContain('Reference: Envelope '.$sent['id'])
        ->and($text[0])->toContain('Document content SHA-256: '.Renderer::modelHash($doc));
});

// ------------------------------------------------------------------ the reference, end to end

it('keeps the reference and hash findable through signing, the verifier and the certificate, in every layout', function (array $doc, array $settings, string $where) {
    $h = fsHarness();
    [$sent, $asSent] = fsFpSend($h, fsFpInput($doc, $settings));
    foreach (['alice@example.com', 'bob@example.com'] as $email) {
        [$token, $session] = fsViewing($h, $email);
        fsSign($h, $token, $session, $email);
    }
    $env = $h['signing']->envelope($sent['id']);
    $final = $h['signing']->finalDocument($sent['id']);
    $hash = Renderer::modelHash($doc);
    $pages = $env['document']['pages'];

    expect($env['status'])->toBe('completed')
        ->and(substr($final, 0, strlen($asSent)))->toBe($asSent)
        ->and((new Verifier([$h['keys']['key_id'] => $h['keys']['public']]))->verify($final, $env['completion'], $h['signing']->events($sent['id']))['ok'])->toBeTrue();
    // Still on the page it was sent on, in the signed PDF.
    $finalText = fsPvText($final);
    expect($finalText[$pages - 1])->toContain('Reference: Envelope '.$sent['id'])
        ->and($finalText[$pages - 1])->toContain('Document content SHA-256: '.$hash);

    // The certificate says what the hash is and where to find both.
    $json = $h['signing']->certificate($sent['id'], 'json');
    expect($json['reference'])->toBe(['envelope' => 'Envelope '.$sent['id'], 'content_sha256' => $hash, 'page' => $pages, 'placement' => $where]);
    $cert = implode("\n", fsPvText($h['signing']->certificate($sent['id'], 'pdf')));
    expect($cert)->toContain('Document content SHA-256: '.$hash)
        ->and($cert)->toContain('page '.$pages);
})->with([
    'signature page (always)' => [fsFpDoc(0, [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice']]), [], 'signature_page'],
    'after the content (auto)' => [fsFpDoc(0, [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice']]), ['final_page' => 'auto'], 'after_content'],
    'footer (auto, fields present)' => [fsFpDoc(70, fsFpOwnFields()), ['final_page' => 'auto'], 'footer'],
    'footer (never)' => [fsFpDoc(0, fsFpOwnFields()), ['final_page' => 'never'], 'footer'],
]);

// ------------------------------------------------------------------ organisation

it('takes final_page from the organisation as its default, and lets a sender choose per envelope', function () {
    $h = fsPvHarness();

    expect($h['signing']->orgSettings('org-1')['final_page'])->toBe('always');
    $row = $h['signing']->setOrgSettings('org-1', ['final_page' => 'auto'], 'user:admin');
    expect($row['final_page'])->toBe('auto')->and($row['per_page'])->toBe('none')
        ->and($h['signing']->orgSettings('org-1')['final_page'])->toBe('auto');

    $doc = fsFpDoc(0, [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice']]);
    expect($h['signing']->create(fsFpInput($doc), 'user:1', 'org-1')['settings']['final_page'])->toBe('auto')
        ->and($h['signing']->create(fsFpInput($doc, ['final_page' => 'always']), 'user:1', 'org-1')['settings']['final_page'])->toBe('always')
        ->and($h['signing']->create(fsFpInput($doc), 'user:1')['settings']['final_page'])->toBe('always');

    // per_page alone leaves final_page where it was.
    expect($h['signing']->setOrgSettings('org-1', ['per_page' => 'initials'], 'user:admin')['final_page'])->toBe('auto');
    fsExpectError(fn () => $h['signing']->setOrgSettings('org-1', ['final_page' => 'sometimes'], 'user:admin'), 'invalid_request');
});

// ------------------------------------------------------------------ previewDocument

it('previewDocument: the as-sent model, which renders to exactly the preview() PDF', function (array $doc, array $settings, string $placement) {
    $h = fsPvHarness();
    $input = fsFpInput($doc, $settings);

    $model = $h['signing']->previewDocument($input);

    expect($model['signature_section']['placement'])->toBe($placement)
        ->and($model['reference'])->toBe(Renderer::PREVIEW_PLACEHOLDER)
        ->and($model['content_sha256'])->toBe(Renderer::PREVIEW_PLACEHOLDER)
        ->and((new Renderer())->render($model)['bytes'])->toBe($h['signing']->preview($input));

    $ids = array_column(array_filter($model['document']['blocks'], fn ($b) => isset($b['id'])), 'id');
    $types = array_column($model['document']['blocks'], 'type');
    if ($placement === 'footer') {
        expect($ids)->not->toContain('final-signature-alice')
            ->and($model['footer']['lines'])->toBe(['Reference: '.Renderer::PREVIEW_PLACEHOLDER, 'Document content SHA-256: '.Renderer::PREVIEW_PLACEHOLDER]);
    } else {
        $start = $model['signature_section']['start'];
        expect($ids)->toContain('final-signature-alice')->and($ids)->toContain('final-date-bob')
            ->and($model['footer'])->toBeNull()
            ->and(array_slice($model['document']['blocks'], 0, $start))->toBe($doc['blocks'])
            ->and($types[$start])->toBe($placement === 'signature_page' ? 'pageBreak' : 'heading');
    }
    // The generated fields are the envelope's own, as create() would make them.
    $created = $h['signing']->create($input, 'user:1');
    expect(array_column($model['fields'], 'id'))->toBe(array_column($created['fields'], 'id'));
})->with([
    'always' => [fsFpDoc(0, [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice']]), [], 'signature_page'],
    'auto, flowing' => [fsFpDoc(0, [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice']]), ['final_page' => 'auto'], 'after_content'],
    'auto, fields present' => [fsFpDoc(70, fsFpOwnFields()), ['final_page' => 'auto'], 'footer'],
    'never' => [fsFpDoc(0, fsFpOwnFields()), ['final_page' => 'never'], 'footer'],
]);

it('previewDocument: the moved section renders to exactly the preview() PDF too', function () {
    $h = fsPvHarness();
    $doc = fsFpDoc(fsFpSplitLines(), [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice'], ['type' => 'dateSignedField', 'id' => 'date-alice', 'signer' => 'alice']]);
    $input = fsFpInput($doc, ['final_page' => 'auto', 'per_page' => 'initials']);

    $model = $h['signing']->previewDocument($input);

    expect($model['signature_section']['placement'])->toBe('signature_page')
        ->and($model['page_fields'])->toBe([
            ['type' => 'initialsField', 'id' => 'every-page-initials-alice', 'signer' => 'alice', 'label' => 'AE', 'required' => true, 'pages' => 'authored'],
            ['type' => 'initialsField', 'id' => 'every-page-initials-bob', 'signer' => 'bob', 'label' => 'BE', 'required' => true, 'pages' => 'authored'],
        ])
        ->and((new Renderer())->render($model)['bytes'])->toBe($h['signing']->preview($input));
});

it('preview() matches what send() renders in the new layouts, apart from the placeholders', function (array $doc, array $settings) {
    $h = fsPvHarness();
    $input = fsFpInput($doc, $settings);
    $preview = $h['signing']->preview($input);
    [$sent, $asSent] = fsFpSend($h, $input);

    $expected = array_map(fn (string $t) => str_replace(
        ['Reference: Envelope '.$sent['id'], 'Document content SHA-256: '.Renderer::modelHash($doc)],
        ['Reference: '.Renderer::PREVIEW_PLACEHOLDER, 'Document content SHA-256: '.Renderer::PREVIEW_PLACEHOLDER],
        $t,
    ), fsPvText($asSent));
    expect(fsPvText($preview))->toBe($expected)
        ->and(Agent::pageCount($preview))->toBe($sent['document']['pages'])
        ->and(Agent::pageCount($preview))->toBeLessThan(Agent::pageCount($h['signing']->preview(fsFpInput($doc))))
        ->and(implode("\n", fsPvText($preview)))->toContain('Reference: '.Renderer::PREVIEW_PLACEHOLDER);
})->with([
    'auto, flowing' => [fsFpDoc(0, [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice']]), ['final_page' => 'auto']],
    'auto, footer' => [fsFpDoc(70, fsFpOwnFields()), ['final_page' => 'auto', 'per_page' => 'signature']],
    'never' => [fsFpDoc(0, fsFpOwnFields()), ['final_page' => 'never']],
]);

it('previewDocument: writes no row, tells no event and sends no mail', function () {
    $h = fsPvHarness();
    $h['signing']->setOrgSettings('org-1', ['per_page' => 'initials', 'final_page' => 'auto'], 'user:admin');
    $before = fsPvRows($h['repo']);

    $a = $h['signing']->previewDocument(fsFpInput(fsFpDoc(0, [['type' => 'signatureField', 'id' => 'sig-alice', 'signer' => 'alice']])), 'org-1');
    $b = $h['signing']->previewDocument(fsFpInput(fsFpDoc(70, fsFpOwnFields()), ['final_page' => 'never']));

    expect($a['document']['blocks'])->not->toBe([])->and($b['footer'])->not->toBeNull()
        ->and(fsPvRows($h['repo']))->toBe($before)
        ->and($h['signing']->listEnvelopes())->toBe([])
        ->and($h['told']->events)->toBe([])
        ->and($h['mailer']->sent)->toBe([]);

    // The instruments are live.
    $h['signing']->send($h['signing']->create(fsFpInput(fsFpDoc(0, fsFpOwnFields())), 'user:1')['id'], 'user:1');
    expect(fsPvRows($h['repo'])['envelopes'])->toBe(1)
        ->and($h['told']->events)->not->toBe([])
        ->and($h['mailer']->sent)->toHaveCount(2);
});

it('previewDocument: refuses invalid input exactly as create() does', function () {
    $h = fsPvHarness();
    $input = ['title' => 'Q', 'document' => fsDocument(), 'signers' => [['id' => 'bob', 'name' => 'B', 'email' => 'bob@example.com']]];
    try {
        $h['signing']->create($input, 'user:1');
        throw new RuntimeException('accepted');
    } catch (FancySign\SignError $create) {
    }
    try {
        $h['signing']->previewDocument($input);
        throw new RuntimeException('accepted');
    } catch (FancySign\SignError $e) {
        expect($e->errorCode)->toBe($create->errorCode)->and($e->getMessage())->toBe($create->getMessage());
    }
});
