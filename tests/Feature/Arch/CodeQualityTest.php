<?php

/**
 * Architecture / quality rules to prevent namespace hallucinations and legacy
 * code creeping back into the codebase.
 *
 * Note: The `Pest\Arch\arch()` helper isn't available in this environment by
 * default. Implement the checks here with file-scans so the tests run reliably
 * in CI and local containers.
 */

test('codebase does not use incorrect Section namespace (Forms)', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path());

    foreach ($files as $file) {
        $path = $file->getRealPath();

        if (! str_ends_with($path, '.php')) {
            continue;
        }

        $content = file_get_contents($path);

        // Sections are layout components and belong to Filament\Schemas\Components
        // If someone imported Section from Forms it's wrong and will cause Class not found
        if (str_contains($content, 'Filament\\Forms\\Components\\Section')) {
            $this->fail("Found incorrect Section namespace 'Filament\\Forms\\Components\\Section' in file: " . $file->getRelativePathname());
        }
    }

    $this->assertTrue(true);
});

test('codebase does not use deprecated Card component', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path());

    foreach ($files as $file) {
        $path = $file->getRealPath();

        if (! str_ends_with($path, '.php')) {
            continue;
        }

        $content = file_get_contents($path);

        if (str_contains($content, 'Filament\\Forms\\Components\\Card') || str_contains($content, 'Card::make')) {
            $this->fail("Found banned 'Card' usage in file: " . $file->getRelativePathname());
        }
    }
    $this->assertTrue(true);
});

test('codebase does not contain legacy is_gateway column reference', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path());

    foreach ($files as $file) {
        $content = file_get_contents($file->getRealPath());

        if (str_contains($content, "'is_gateway'") || str_contains($content, '"is_gateway"')) {
            $this->fail("Found legacy 'is_gateway' in file: " . $file->getRelativePathname());
        }
    }
    $this->assertTrue(true);
});

test('clusters do not depend on root resources', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path('Filament/Clusters'));

    foreach ($files as $file) {
        $content = file_get_contents($file->getRealPath());

        if (str_contains($content, 'App\\Filament\\Resources')) {
            $this->fail("Found forbidden dependency 'App\\Filament\\Resources' in file: " . $file->getRelativePathname());
        }
    }
    $this->assertTrue(true);
});
