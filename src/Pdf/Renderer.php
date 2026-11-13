<?php

declare(strict_types=1);

namespace FancySign\Pdf;

use FinePrint\Agent;
use FinePrint\Writer\Incremental;
use FinePrint\Writer\Signable;

/**
 * Every PDF this package makes, through fine-print:
 *
 *   - asSent(): the document plus the generated final signature page, with
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
     * @param  array<string, mixed>  $envelope
     * @return array{bytes: string, pages: int, authoredPages: int, fields: list<array<string, mixed>>}
     */
    public function asSent(array $envelope, string $pageTitle): array
    {
        $signers = array_map(fn (array $s) => ['id' => $s['id'], 'name' => $s['name'], 'email' => $s['email']], $envelope['signers']);
        $options = ['signaturePage' => [
            'title' => $pageTitle,
            'reference' => 'Envelope '.$envelope['id'],
            'contentHash' => self::modelHash($envelope['document']['model']),
            'signers' => $signers,
        ]];
        $perPage = $envelope['settings']['per_page'];
        if ($perPage !== 'none') {
            $options['pageMarks'] = ['kind' => $perPage, 'signers' => array_map(fn (array $s) => ['id' => $s['id'], 'label' => self::initials($s['name'])], $envelope['signers'])];
        }

        return Signable::write($envelope['document']['model'], $options);
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
     */
    public function certificate(array $envelope, array $events): string
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
            ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Signers']]],
        ];
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
            if ($e['type'] === 'page.viewed') {
                $line .= '  page '.$e['data']['page'].', '.$e['data']['ms'].' ms';
            }
            if ($e['type'] === 'signer.declined') {
                $line .= '  reason: '.$e['data']['reason'];
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
}
