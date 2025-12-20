<?php

declare(strict_types=1);


it('does not reference removed Filament form components', function () {
    $forbidden = [
        'Filament\\Forms\\Components\\Fieldset',
        'Filament\\Forms\\Components\\Grid',
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'))
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        foreach ($forbidden as $class) {
            $this->assertStringNotContainsString(
                $class,
                $contents,
                "Forbidden Filament component {$class} found in {$file->getPathname()}"
            );
        }
    }
});
