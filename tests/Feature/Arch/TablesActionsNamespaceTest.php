<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

it('does not use Filament\\Tables\\Actions namespace (use Filament\\Actions instead)', function () {
    $finder = (new Finder())
        ->files()
        ->in(app_path())
        ->name('*.php')
        ->contains('/Filament\\\\Tables\\\\Actions\\\\/'); // deprecated v4 namespace

    $offenders = [];
    foreach ($finder as $file) {
        $offenders[] = $file->getRelativePathname();
    }

    expect($offenders, 'Replace Filament\\Tables\\Actions\\* with Filament\\Actions\\*')
        ->toBeEmpty();
});

