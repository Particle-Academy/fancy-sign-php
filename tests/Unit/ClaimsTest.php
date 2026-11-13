<?php

declare(strict_types=1);

/*
 * The legal-claims wording is binding (plan section 10). These phrases must
 * never appear in what a reader sees, as KitVersionTest guards versions.
 */

const FS_FORBIDDEN = [
    'legally compliant', 'legally binding', 'eidas compliant', 'eidas-compliant', 'esign compliant', 'ueta compliant',
    'esign/ueta compliant', 'court-proof', 'court proof', 'qualified electronic signature', 'equivalent to a handwritten',
];

it('never makes a forbidden legal claim in the README, the AGENTS file, the changelog or the composer description', function (string $file) {
    $text = strtolower((string) file_get_contents(dirname(__DIR__, 2).'/'.$file));
    expect($text)->not->toBe('');
    foreach (FS_FORBIDDEN as $phrase) {
        expect(str_contains($text, $phrase))->toBeFalse("{$file} says \"{$phrase}\"");
    }
})->with(['README.md', 'AGENTS.md', 'CHANGELOG.md', 'composer.json']);

it('says what it is designed for and recommends legal review', function () {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');

    expect($readme)->toContain('Designed to support simple electronic signatures (SES)')
        ->toContain('Have it reviewed by a lawyer before relying on it.');
});

it('never calls the SES phase AdES', function () {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');

    expect(preg_match('/produces? (an )?advanced electronic signatures?/i', $readme))->toBe(0);
});
