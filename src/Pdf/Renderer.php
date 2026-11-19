<?php

declare(strict_types=1);

namespace FancySign\Pdf;

use FinePrint\Agent;
use FinePrint\Exceptions\SchemaException;
use FinePrint\Exchange\Portable;
use FinePrint\Writer\Incremental;
use FinePrint\Writer\PdfWriter;
use FinePrint\Writer\Schema;
use FinePrint\Writer\Signable;
use FinePrint\Writer\Validator;

/**
 * Every PDF this package makes, through fine-print:
 *
 *   - build() then render(): the as-sent document. build() makes the MODEL
 *     (the document with the generated signature section as nodes, placed by
 *     `settings.final_page`); render() turns any model into the PDF, with
 *     per-page marks on the authored pages when the envelope asks for them.
 *     fine-print returns the placement map; nothing here invents a coordinate.
 *   - final(): the as-sent bytes plus ONE incremental update that draws every
 *     signer's marks into their fields. The as-sent file stays a byte-for-byte
 *     prefix.
 *   - certificate(): the certificate of completion, a separate PDF (it names
 *     the final hash, so it cannot live inside the file it describes).
 *
 * A drawn mark is strokes, drawn as vector paths: no client image is ever
 * decoded.
 */
final class Renderer
{
    /** Drawn-mark coordinates are stored as integers on this grid (canonical JSON refuses floats). */
    public const GRID = 10000;

    /**
     * What a preview prints where the envelope reference and the content hash
     * go: neither is assigned before send, and a preview must never be mistaken
     * for the sent document.
     */
    public const PREVIEW_PLACEHOLDER = 'PREVIEW, assigned when sent';

    /** Where the envelope reference and the content hash are printed: the three layouts of the as-sent document. */
    public const PLACEMENTS = ['signature_page', 'after_content', 'footer'];

    /**
     * The as-sent document, rendered: build() then render(). With `$preview`,
     * the reference and content hash read PREVIEW_PLACEHOLDER in the same
     * place. Signing::send(), preview() and previewDocument() all come through
     * build(), so they cannot drift.
     *
     * @param  array<string, mixed>  $envelope
     * @return array{bytes: string, pages: int, authoredPages: int, fields: list<array<string, mixed>>, placement: string}
     */
    public function asSent(array $envelope, string $pageTitle, bool $preview = false): array
    {
        $model = $this->build($envelope, $pageTitle, $preview);

        return $this->render($model) + ['placement' => $model['signature_section']['placement']];
    }

    /**
     * The as-sent document MODEL: the document with the generated signature
     * section appended as nodes, plus what render() needs that is not a block
     * of the document (the per-page field nodes, the footer line). Stores and
     * sends nothing. With `$preview` the reference and content hash read
     * PREVIEW_PLACEHOLDER.
     *
     * Where the section goes is `settings.final_page`:
     *   - always: a page of its own after the content (0.4.0, byte for byte).
     *   - auto, when the envelope has generated fields (a signer lacks a
     *     required signature or date field): the section FLOWS after the last
     *     content when the whole of it fits on that page; otherwise it is the
     *     page of its own, moved whole. Measured by rendering, never estimated.
     *   - never, or auto with nothing generated: no section; the reference and
     *     content hash are a footer line on the last page.
     *
     * @param  array<string, mixed>  $envelope  the stored envelope shape
     * @return array{document: array<string, mixed>, signature_section: array{placement: string, start: ?int}, reference: string, content_sha256: string, footer: ?array{page: string, lines: list<string>}, page_fields: list<array<string, mixed>>, fields: list<array<string, mixed>>, preview: bool}
     *
     * @throws SchemaException when a signer or the page title is outside fine-print's limits
     */
    public function build(array $envelope, string $pageTitle, bool $preview = false): array
    {
        $doc = $envelope['document']['model'];
        $reference = $preview ? self::PREVIEW_PLACEHOLDER : 'Envelope '.$envelope['id'];
        $hash = $preview ? self::PREVIEW_PLACEHOLDER : self::modelHash($doc);
        $signers = array_map(fn (array $s) => ['id' => $s['id'], 'name' => $s['name'], 'email' => $s['email']], $envelope['signers']);
        self::checkSection($pageTitle, $reference, $hash, $signers);

        $perPage = $envelope['settings']['per_page'];
        $pageFields = [];
        if ($perPage !== 'none') {
            foreach ($envelope['signers'] as $s) {
                $pageFields[] = ['type' => $perPage === 'initials' ? 'initialsField' : 'signatureField', 'id' => "every-page-{$perPage}-{$s['id']}",
                    'signer' => $s['id'], 'label' => self::initials($s['name']), 'required' => true, 'pages' => 'authored'];
            }
        }
        $model = [
            'document' => $doc,
            'signature_section' => ['placement' => 'footer', 'start' => null],
            'reference' => $reference,
            'content_sha256' => $hash,
            'footer' => null,
            'page_fields' => $pageFields,
            'fields' => array_map(fn (array $f) => array_diff_key($f, ['placement' => true]), $envelope['fields']),
            'preview' => $preview,
        ];

        $blocks = is_array($doc['blocks'] ?? null) ? array_values($doc['blocks']) : [];
        $start = count($blocks);
        $generated = array_filter($envelope['fields'], fn (array $f) => ($f['anchor']['final_page'] ?? false) === true || ($f['anchor']['signature_section'] ?? false) === true);
        if ($generated === []) {
            $model['footer'] = ['page' => 'last', 'lines' => ['Reference: '.$reference, 'Document content SHA-256: '.$hash]];

            return $model;
        }

        $page = $model;
        $page['document']['blocks'] = array_merge($blocks, [['type' => 'pageBreak', 'page' => []]], self::section($pageTitle, $reference, $hash, $signers, flow: false));
        $page['signature_section'] = ['placement' => 'signature_page', 'start' => $start];
        if (($envelope['settings']['final_page'] ?? 'always') === 'always') {
            return $page;
        }

        $flow = $model;
        $flow['document']['blocks'] = array_merge($blocks, self::section($pageTitle, $reference, $hash, $signers, flow: true));
        $flow['signature_section'] = ['placement' => 'after_content', 'start' => $start];

        // The whole section fits after the content exactly when it adds no page.
        return $this->render($flow)['pages'] === $this->render($model)['pages'] ? $flow : $page;
    }

    /**
     * Render a model from build() or Signing::previewDocument() to the as-sent
     * PDF. The model is validated as Signable validates its input.
     *
     * @param  array<string, mixed>  $model
     * @return array{bytes: string, pages: int, authoredPages: int, fields: list<array<string, mixed>>}
     *
     * @throws SchemaException when the document, the section or the model is invalid
     */
    public function render(array $model): array
    {
        $doc = is_array($model['document'] ?? null) ? $model['document'] : [];
        $blocks = is_array($doc['blocks'] ?? null) ? array_values($doc['blocks']) : [];
        $placement = $model['signature_section']['placement'] ?? null;
        $start = $model['signature_section']['start'] ?? null;
        if (! in_array($placement, self::PLACEMENTS, true) || ($placement === 'footer') !== ($start === null)
            || ($start !== null && (! is_int($start) || $start < 0 || $start > count($blocks)))) {
            throw new SchemaException('The document model failed validation.', [['path' => 'signature_section', 'message' => 'placement is signature_page or after_content with a start, or footer with none']]);
        }
        $section = $start === null ? [] : array_slice($blocks, $start + ($placement === 'signature_page' ? 1 : 0));
        $doc['blocks'] = $start === null ? $blocks : array_slice($blocks, 0, $start);

        // As Signable::write: expand, then validate with field nodes allowed.
        $doc = Portable::expand($doc)['doc'];
        $errors = (new Validator(fields: true))->validate($doc);
        if ($section !== []) {
            // keepNext on a field node is the signature page's own (fine-print's Signable puts it
            // there, past its validator); the layout honours it, the authoring schema does not list it.
            $check = array_map(fn ($b) => is_array($b) && in_array($b['type'] ?? null, Schema::FIELD_TYPES, true) ? array_diff_key($b, ['keepNext' => true]) : $b, $section);
            foreach ((new Validator(fields: true))->validate(['blocks' => $check]) as $e) {
                $errors[] = ['path' => 'signature_section.'.$e['path'], 'message' => $e['message']];
            }
        }
        $marks = self::pageMarks($model['page_fields'] ?? [], $errors);
        if ($errors !== []) {
            throw new SchemaException('Document or signing options failed validation.', $errors);
        }
        $options = ['fields' => true];
        if ($placement === 'signature_page') {
            $options['signaturePage'] = $section;
        } elseif ($placement === 'after_content') {
            $doc['blocks'] = array_merge($doc['blocks'], $section);
        }
        if ($marks !== null) {
            $options['pageMarks'] = $marks;
        }

        $out = (new PdfWriter($options))->write($doc);
        $bytes = $out['bytes'];
        $footer = $model['footer'] ?? null;
        if (is_array($footer) && is_array($footer['lines'] ?? null) && $footer['lines'] !== []) {
            $bytes = self::footer($bytes, $doc, $out['pages'], $footer['lines']);
        }

        return ['bytes' => $bytes, 'pages' => $out['pages'], 'authoredPages' => $out['authoredPages'], 'fields' => $out['fields']];
    }

    /**
     * The generated signature section. With `$flow` false these are exactly the
     * blocks fine-print's Signable makes for a signature page, which keeps
     * `always` byte for byte what 0.4.0 rendered. With `$flow` the heading takes
     * some room from the content above it and every block keeps with the next.
     *
     * @param  list<array{id: string, name: string, email: string}>  $signers
     * @return list<array<string, mixed>>
     */
    private static function section(string $title, string $reference, string $hash, array $signers, bool $flow): array
    {
        $small = fn (string $text): array => ['type' => 'paragraph', 'runs' => [['text' => $text, 'size' => 8, 'color' => '#6B7280']]];
        $blocks = [['type' => 'heading', 'level' => 2, 'runs' => [['text' => $title !== '' ? $title : 'Signatures']]]];
        if ($reference !== '') {
            $blocks[] = $small('Reference: '.$reference);
        }
        if ($hash !== '') {
            $blocks[] = $small('Document content SHA-256: '.$hash);
        }
        foreach ($signers as $s) {
            $runs = [['text' => $s['name'], 'bold' => true]];
            if ($s['email'] !== '') {
                $runs[] = ['text' => '  '.$s['email'], 'color' => '#4B5563'];
            }
            $blocks[] = ['type' => 'paragraph', 'runs' => $runs, 'keepNext' => true, 'spaceBefore' => 14];
            $blocks[] = ['type' => 'signatureField', 'id' => 'final-signature-'.$s['id'], 'signer' => $s['id'], 'label' => 'Signature', 'keepNext' => true];
            $blocks[] = ['type' => 'dateSignedField', 'id' => 'final-date-'.$s['id'], 'signer' => $s['id'], 'label' => 'Date signed'];
        }
        if ($flow) {
            $blocks[0]['spaceBefore'] = 24;
            $last = count($blocks) - 1;
            foreach (array_keys($blocks) as $i) {
                if ($i < $last) {
                    $blocks[$i]['keepNext'] = true;
                }
            }
        }

        return $blocks;
    }

    /**
     * fine-print's limits on a signature page, checked as Signable checks them.
     *
     * @param  list<array{id: string, name: string, email: string}>  $signers
     */
    private static function checkSection(string $title, string $reference, string $hash, array $signers): void
    {
        $errors = [];
        foreach (['title' => $title, 'reference' => $reference, 'contentHash' => $hash] as $key => $v) {
            if (mb_strlen($v, 'UTF-8') > Signable::MAX_TEXT) {
                $errors[] = ['path' => "signaturePage.{$key}", 'message' => "{$key} must be a string of at most ".Signable::MAX_TEXT.' characters'];
            }
        }
        if ($signers === [] || count($signers) > Signable::MAX_SIGNERS) {
            $errors[] = ['path' => 'signaturePage.signers', 'message' => 'signers must be a list of 1 to '.Signable::MAX_SIGNERS];
        }
        foreach ($signers as $i => $s) {
            foreach (['name', 'email'] as $key) {
                if (mb_strlen($s[$key], 'UTF-8') > Signable::MAX_TEXT) {
                    $errors[] = ['path' => "signaturePage.signers[{$i}].{$key}", 'message' => "{$key} must be a string of at most ".Signable::MAX_TEXT.' characters'];
                }
            }
        }
        if ($errors !== []) {
            throw new SchemaException('Document or signing options failed validation.', $errors);
        }
    }

    /**
     * A model's per-page field nodes as fine-print's pageMarks option: one kind
     * for all of them, a mark per signer.
     *
     * @param  list<array{path: string, message: string}>  $errors
     * @return array{kind: string, signers: list<array{id: string, label: string}>}|null
     */
    private static function pageMarks(mixed $nodes, array &$errors): ?array
    {
        if (! is_array($nodes) || ! array_is_list($nodes) || count($nodes) > Signable::MAX_SIGNERS) {
            $errors[] = ['path' => 'page_fields', 'message' => 'page_fields is a list of at most '.Signable::MAX_SIGNERS.' field nodes'];

            return null;
        }
        $kinds = [];
        $signers = [];
        foreach ($nodes as $i => $n) {
            $kind = is_array($n) ? (['initialsField' => 'initials', 'signatureField' => 'signature'][$n['type'] ?? ''] ?? null) : null;
            $id = $n['signer'] ?? null;
            $label = $n['label'] ?? '';
            if ($kind === null || ! is_string($id) || preg_match(Schema::FIELD_ID, $id) !== 1 || ! is_string($label) || preg_match(Signable::MARK_LABEL, $label) !== 1) {
                $errors[] = ['path' => "page_fields[{$i}]", 'message' => 'a per-page field is an initialsField or signatureField with a signer and a short label'];

                continue;
            }
            $kinds[$kind] = true;
            $signers[] = ['id' => $id, 'label' => $label];
        }
        if (count($kinds) > 1) {
            $errors[] = ['path' => 'page_fields', 'message' => 'every per-page field is the same kind'];
        }

        return $kinds === [] ? null : ['kind' => (string) array_key_first($kinds), 'signers' => $signers];
    }

    /**
     * The fixed footer line of a document with no generated section: the
     * envelope reference and the content hash, small and grey, in the bottom
     * margin of the LAST page, below the document's own footer band and left of
     * any per-page marks. Drawn as an incremental update, so it is part of the
     * as-sent bytes, and the as-sent file stays a prefix of the final one.
     *
     * @param  array<string, mixed>  $doc  the expanded document as rendered
     * @param  list<string>  $lines
     */
    private static function footer(string $pdf, array $doc, int $pages, array $lines): string
    {
        $stretches = Schema::stretches($doc);
        $m = Schema::margins($stretches[count($stretches) - 1]['doc']);
        $size = 6.0;
        $y = max(4.0, min(14.0, $m['bottom'] - 22.0));
        $items = [];
        foreach (array_reverse(array_values($lines)) as $i => $line) {
            $items[] = ['type' => 'text', 'x' => $m['left'], 'y' => $y + $i * ($size + 2.0), 'size' => $size, 'text' => (string) $line, 'color' => '#6B7280'];
        }

        return Incremental::overlay($pdf, [['page' => $pages, 'items' => $items]]);
    }

    /** A hash of the document model as stored, printed on the signature page. */
    public static function modelHash(array $model): string
    {
        return hash('sha256', (string) json_encode($model, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param  array<string, mixed>  $envelope  with signed signers (mark, signed_at) and placed fields
     */
    public function final(string $asSent, array $envelope): string
    {
        $byPage = [];
        $signers = array_column($envelope['signers'], null, 'id');
        foreach ($envelope['fields'] as $field) {
            $signer = $signers[$field['signer_id']] ?? null;
            if ($signer === null || $signer['status'] !== 'signed' || ! is_array($field['placement'] ?? null)) {
                continue;
            }
            foreach ($field['placement'] as $p) {
                foreach ($this->fill($field['kind'], $p['rect'], $signer) as $item) {
                    $byPage[$p['page']][] = $item;
                }
            }
        }
        ksort($byPage);
        $marks = [];
        foreach ($byPage as $page => $items) {
            $marks[] = ['page' => $page, 'items' => $items];
        }

        return Incremental::overlay($asSent, $marks);
    }

    /**
     * What goes into one field box.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rect
     * @param  array<string, mixed>  $signer
     * @return list<array<string, mixed>>
     */
    private function fill(string $kind, array $rect, array $signer): array
    {
        [$x0, $y0, $x1, $y1] = $rect;
        $w = $x1 - $x0;
        $h = $y1 - $y0;
        if ($kind === 'date_signed') {
            $size = max(6.0, min(10.0, $h * 0.6));

            return [['type' => 'text', 'x' => $x0 + 2, 'y' => $y0 + ($h - $size) / 2 + 1, 'size' => $size,
                'text' => substr((string) $signer['signed_at'], 0, 10), 'color' => '#111827']];
        }
        $mark = $signer['mark'];
        if ($mark['kind'] === 'typed') {
            $text = $kind === 'initials' ? self::initials($mark['text']) : $mark['text'];
            $text = $text === '' ? $mark['text'] : $text;
            $size = max(6.0, min($h * 0.6, 22.0, ($w - 4) / max(1, 0.55 * mb_strlen($text, 'UTF-8'))));

            return [['type' => 'text', 'x' => $x0 + 2, 'y' => $y0 + ($h - $size) / 2 + 1, 'size' => $size, 'text' => $text, 'color' => '#1E3A8A']];
        }
        $pad = 0.06;
        $strokes = [];
        foreach ($mark['strokes'] as $stroke) {
            $strokes[] = array_map(fn (array $pt) => [
                round($x0 + $w * ($pad + (1 - 2 * $pad) * $pt[0] / self::GRID), 2),
                round($y1 - $h * ($pad + (1 - 2 * $pad) * $pt[1] / self::GRID), 2),
            ], $stroke);
        }

        return [['type' => 'path', 'strokes' => $strokes, 'width' => $kind === 'initials' ? 0.9 : 1.2, 'color' => '#1E3A8A']];
    }

    /** Up to three initials, ASCII letters and digits only (anything else is left out). */
    public static function initials(string $name): string
    {
        $out = '';
        foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $c = mb_substr($word, 0, 1, 'UTF-8');
            if (preg_match('/^[A-Za-z0-9]$/', $c) === 1) {
                $out .= strtoupper($c);
            }
            if (strlen($out) === 3) {
                break;
            }
        }

        return $out;
    }

    /**
     * The certificate of completion. Every event in seq order with its source;
     * a browser-reported event says so.
     *
     * @param  array<string, mixed>  $envelope  wire shape
     * @param  list<array<string, mixed>>  $events
     * @param  array{envelope: string, content_sha256: string, page: ?int, placement: string}|null  $reference  where the as-sent document prints its reference and content hash
     */
    public function certificate(array $envelope, array $events, ?array $reference = null): string
    {
        $p = fn (string $text, array $run = []) => ['type' => 'paragraph', 'runs' => [['text' => $text] + $run]];
        $small = fn (string $text) => $p($text, ['size' => 8]);
        $c = $envelope['completion'];
        $blocks = [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Certificate of completion']]],
            $p($envelope['title'], ['bold' => true]),
            $small('Envelope: '.$envelope['id'].'    Status: '.$envelope['status']),
            $small('As-sent document SHA-256: '.($envelope['document']['as_sent_sha256'] ?? '-')),
            $small('Final document SHA-256: '.($envelope['document']['final_sha256'] ?? '-')),
        ];
        if ($reference !== null) {
            $where = ['signature_page' => 'on the signature page', 'after_content' => 'after the content', 'footer' => 'in the footer'][$reference['placement']] ?? $reference['placement'];
            $blocks[] = $small('Document content SHA-256: '.$reference['content_sha256']);
            $blocks[] = $small('The reference ('.$reference['envelope'].') and the content hash are printed '.$where
                .($reference['page'] !== null ? ', page '.$reference['page'] : '').' of the as-sent document.');
        }
        $blocks[] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Signers']]];
        foreach ($envelope['signers'] as $s) {
            $blocks[] = $p($s['name'].' <'.$s['email'].'>', ['bold' => true]);
            $blocks[] = $small('Status: '.$s['status']
                .($s['consent'] !== null ? '    Consent: '.$s['consent']['at'].' (version '.$s['consent']['version'].')' : '')
                .($s['signed_at'] !== null ? '    Signed: '.$s['signed_at'] : '')
                .($s['declined_at'] !== null ? '    Declined: '.$s['declined_at'] : ''));
            foreach ($events as $e) {
                if ($e['actor'] === 'signer:'.$s['id'] && in_array($e['type'], ['otp.verified', 'signer.signed'], true)) {
                    $blocks[] = $small('  '.$e['type'].' at '.$e['at'].'  IP '.($e['ip'] ?? '-').'  '.($e['user_agent'] ?? ''));
                }
            }
        }
        $blocks[] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Events']]];
        foreach ($events as $e) {
            $line = '#'.$e['seq'].'  '.$e['at'].'  '.$e['type'].'  by '.$e['actor']
                .($e['source'] === 'client' ? '  (reported by the signer\'s browser)' : '')
                .'  IP '.($e['ip'] ?? '-');
            $data = (array) $e['data'];  // a store may hold it as an object (EventChain keeps JSON objects as objects)
            if ($e['type'] === 'page.viewed') {
                $line .= '  page '.$data['page'].', '.$data['ms'].' ms';
            }
            if ($e['type'] === 'signer.declined') {
                $line .= '  reason: '.$data['reason'];
            }
            if ($e['type'] === 'signer.requested_changes') {
                $line .= '  asked: '.$data['text'];
            }
            $blocks[] = $small($line);
        }
        if (is_array($c)) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Seal']]];
            $blocks[] = $small('Chain head: '.$c['chain_head']);
            $blocks[] = $small('Completion token: '.$c['token']);
            $blocks[] = $small('Seal (Ed25519, key '.$c['key_id'].'): '.$c['seal']);
        }
        $blocks[] = $small('This certificate records a simple electronic signature process. Have it reviewed by a lawyer before relying on it.');

        return Agent::toBytes(['title' => 'Certificate of completion', 'blocks' => $blocks]);
    }

    /**
     * The certificate of a DATA-mode envelope (CC-fancy-0052 section 5.2). It
     * describes the signing, never the document: the data hash, each signer's
     * sealed signature record and rendering claim (labelled as reported by
     * the browser), the events, the completion and an attached final. Made
     * only when asked; nothing in the signing path depends on it.
     *
     * @param  array<string, mixed>  $envelope  wire shape
     * @param  list<array<string, mixed>>  $events
     */
    public function dataCertificate(array $envelope, array $events): string
    {
        $p = fn (string $text, array $run = []) => ['type' => 'paragraph', 'runs' => [['text' => $text] + $run]];
        $small = fn (string $text) => $p($text, ['size' => 8]);
        $doc = $envelope['document'];
        $c = $envelope['completion'];
        $final = $envelope['final'] ?? null;
        $blocks = [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Certificate of completion']]],
            $p($envelope['title'], ['bold' => true]),
            $small('Envelope: '.$envelope['id'].'    Status: '.$envelope['status'].'    Mode: data'),
            $small('Signed data SHA-256 ('.$doc['canonical'].', '.$doc['data_bytes'].' bytes):'),
            $small($doc['data_sha256']),
            $small('Fields SHA-256: '.$doc['fields_sha256'].($doc['profile'] !== null ? '    Profile: '.$doc['profile'] : '')),
            $small('In data mode the package seals the data and the signing evidence; how the data was displayed to each signer, and any document made from it, are the host application\'s.'),
        ];
        $blocks[] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Signers']]];
        foreach ($envelope['signers'] as $s) {
            $blocks[] = $p($s['name'].' <'.$s['email'].'>', ['bold' => true]);
            $blocks[] = $small('Status: '.$s['status']
                .($s['consent'] !== null ? '    Consent: '.$s['consent']['at'].' (version '.$s['consent']['version'].')' : '')
                .($s['signed_at'] !== null ? '    Signed: '.$s['signed_at'] : '')
                .($s['declined_at'] !== null ? '    Declined: '.$s['declined_at'] : ''));
            $record = $s['signature'] ?? null;
            if (is_array($record)) {
                $blocks[] = $small('Signature record ('.$record['method'].'):');
                $blocks[] = $small($record['token']);
                $blocks[] = $small('Rendering, reported by the signer\'s browser: '
                    .($record['renderer'] !== null ? $record['renderer'] : 'none reported'));
            }
            foreach ($events as $e) {
                if ($e['actor'] === 'signer:'.$s['id'] && in_array($e['type'], ['otp.verified', 'signer.signed'], true)) {
                    $blocks[] = $small('  '.$e['type'].' at '.$e['at'].'  IP '.($e['ip'] ?? '-').'  '.($e['user_agent'] ?? ''));
                }
            }
        }
        $blocks[] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Events']]];
        foreach ($events as $e) {
            $line = '#'.$e['seq'].'  '.$e['at'].'  '.$e['type'].'  by '.$e['actor']
                .($e['source'] === 'client' ? '  (reported by the signer\'s browser)' : '')
                .'  IP '.($e['ip'] ?? '-');
            $data = (array) $e['data'];
            if ($e['type'] === 'page.viewed') {
                $line .= '  page '.$data['page'].', '.$data['ms'].' ms';
            }
            if ($e['type'] === 'document.rendered') {
                $line .= '  '.$data['pages'].' page(s)';
            }
            if ($e['type'] === 'signer.declined') {
                $line .= '  reason: '.$data['reason'];
            }
            if ($e['type'] === 'signer.requested_changes') {
                $line .= '  asked: '.$data['text'];
            }
            $blocks[] = $small($line);
        }
        if (is_array($c)) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Seal']]];
            $blocks[] = $small('Chain head: '.$c['chain_head']);
            $blocks[] = $small('Completion token:');
            $blocks[] = $small($c['token']);
            $blocks[] = $small('Seal (Ed25519, key '.$c['key_id'].'): '.$c['seal']);
        }
        $blocks[] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Final document']]];
        if (is_array($final)) {
            $blocks[] = $small('Registered by '.$final['attached_by'].' at '.$final['attached_at'].' ('.$final['final_bytes'].' bytes). SHA-256:');
            $blocks[] = $small($final['final_sha256']);
            $blocks[] = $small('Final record:');
            $blocks[] = $small($final['token']);
            $blocks[] = $small('The host registered this hash for this completed signing. It does not show that the document presents the data faithfully.');
        } else {
            $blocks[] = $small('No final document has been registered for this envelope.');
        }
        $blocks[] = $small('This certificate records a simple electronic signature process. Have it reviewed by a lawyer before relying on it.');

        return Agent::toBytes(['title' => 'Certificate of completion', 'blocks' => $blocks]);
    }
}
