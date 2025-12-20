<?php

/**
 * Ensure all Resources that define CreateAction or EditAction use slideOver()
 * to enforce consistent SlideOver modal UX across the admin panel.
 */

test('resources use slideOver for create and edit actions', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path('Filament'));

    foreach ($files as $file) {
        $path = $file->getRealPath();

        if (! str_ends_with($path, '.php')) {
            continue;
        }

        $content = file_get_contents($path);

        // Only check resources (skip other Filament artifacts)
        if (! str_contains($path, DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR)) {
            continue;
        }

        // Check CreateAction patterns
        if (str_contains($content, 'CreateAction::make(') || str_contains($content, "CreateAction::make()")) {
            if (! str_contains($content, '->slideOver(')) {
                $this->fail('Resource ' . $file->getRelativePathname() . " defines CreateAction::make() but does not use ->slideOver()");
            }
        }

        // Check EditAction patterns
        if (str_contains($content, 'EditAction::make(') || str_contains($content, "EditAction::make()")) {
            if (! str_contains($content, '->slideOver(')) {
                $this->fail('Resource ' . $file->getRelativePathname() . " defines EditAction::make() but does not use ->slideOver()");
            }
        }
    }

    $this->assertTrue(true);
});
