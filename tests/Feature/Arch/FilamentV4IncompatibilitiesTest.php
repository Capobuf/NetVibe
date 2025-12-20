<?php

use Symfony\Component\Finder\Finder;

it('does not reference Filament Tables Actions namespace (v3 style)', function () {
    $finder = (new Finder())->in(app_path())->files()->contains('/Tables\\\\Actions/');

    // If any occurrences exist, list them for clarity in failure output
    $count = iterator_count($finder);
    if ($count > 0) {
        foreach ($finder as $file) {
            fwrite(STDERR, "Found forbidden pattern in: " . $file->getRealPath() . "\n");
        }
    }

    expect($count)->toBe(0);
});

it('flags common deprecated Filament component uses', function () {
    $finder = (new Finder())->in(app_path())->files()->contains('/Forms\\\\Components\\\\Card|Tables\\\\Actions\\\\Action/');

    expect(iterator_count($finder))->toBe(0);
});
