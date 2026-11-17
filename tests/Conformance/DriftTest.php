<?php

declare(strict_types=1);

use FancySign\Tests\Conformance\Export;
use ParticleAcademy\Conformance\Conformance;

/*
 * The exporter, run NOW into a temporary directory, must reproduce the pinned
 * cases.json of every sign suite exactly.
 *
 * Red here means this package (or the exporter) changed what a port is held
 * to. That may be intended, and then it is a CONTRACT change: run
 * `composer conformance:export`, review the diff in fancy-conformance, release
 * it, and move the pin. It is never fixed by editing a row by hand.
 */

it('re-exports the pinned sign suites without a byte of drift', function (): void {
    $tmp = sys_get_temp_dir().'/fs-drift-'.bin2hex(random_bytes(6));
    try {
        $counts = Export::write($tmp);
        $installed = Conformance::root().'/suites';
        foreach (Export::SUITES as $suite) {
            $fresh = str_replace("\r\n", "\n", (string) file_get_contents("{$tmp}/{$suite}/cases.json"));
            $pinned = str_replace("\r\n", "\n", (string) file_get_contents("{$installed}/{$suite}/cases.json"));

            expect($fresh === $pinned)->toBeTrue("{$suite}/cases.json drifted: run composer conformance:export and review the diff")
                ->and($counts[$suite])->toBeGreaterThan(0);
        }
    } finally {
        foreach (Export::SUITES as $suite) {
            @unlink("{$tmp}/{$suite}/cases.json");
            @rmdir("{$tmp}/{$suite}");
        }
        @rmdir("{$tmp}/sign");
        @rmdir($tmp);
    }
});
