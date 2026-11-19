<?php

declare(strict_types=1);

use FancySign\Tests\Conformance\Export;
use ParticleAcademy\Conformance\Conformance;

/*
 * PHP passes its OWN exported rows: every `sign/*` suite, read back from the
 * installed fixture set through fancy-conformance's loader, replayed through
 * this package, compared by the loader's own equals(). This is the reference
 * half of a port's contract (fancy-sign-js): the rows are only worth something
 * if the implementation they came from still produces them after the trip
 * through JSON and disk.
 *
 * The fixture set is pinned EXACTLY in require-dev, and the first two tests
 * make the pin a checked claim.
 */
const FSC_PINNED_SUITE_VERSION = '0.56.0';

/** Exact row counts. A row lost between the exporter and the loader fails here. */
const FSC_ROWS = [
    'sign/chain' => 39,
    'sign/completion' => 20,
    'sign/data' => 13,
    'sign/fields' => 11,
    'sign/otp' => 24,
];

it('runs against the pinned fixture set, and says which', function (): void {
    expect(Conformance::version())->toBe(FSC_PINNED_SUITE_VERSION);
});

it('pins the fixture set exactly in composer.json', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require-dev']['particle-academy/fancy-conformance'] ?? null)->toBe(FSC_PINNED_SUITE_VERSION);
});

it('runs every sign suite in the fixture set, and no other', function (): void {
    $found = array_values(array_filter(Conformance::listSuites(), fn (string $s) => str_starts_with($s, 'sign/')));
    sort($found);
    $ours = Export::SUITES;
    sort($ours);

    expect($found)->toBe($ours)->and(array_keys(FSC_ROWS))->toBe(Export::SUITES);
});

it('passes every row of its own exported suite', function (string $suite): void {
    expect(count(Conformance::cases($suite)))->toBe(FSC_ROWS[$suite]);
    // Compare as JSON, the way the golden was read: an empty object answer ({}) and
    // the decoded golden ([]) are the same JSON value, and PHP keeps them apart.
    $summary = Conformance::runTable($suite, fn (array $case) => json_decode(json_encode(
        Export::call($suite, (string) $case['fn'], (array) $case['input']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

    expect($summary['ok'])->toBeTrue(Conformance::formatSummary($summary))
        ->and($summary['skipped'])->toBe(0);
})->with(Export::SUITES);
