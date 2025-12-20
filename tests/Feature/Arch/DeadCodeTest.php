<?php

/**
 * Detect Filament Pages and Widgets that live on disk under
 * app/Filament/Clusters/.../Resources/.../Pages and Widgets and
 * ensures they are actually referenced/registered by the application.
 * anywhere else in the application. This helps find orphaned classes left
 * behind after refactors.
 */


test('filament pages and widgets are referenced somewhere in the app', function () {
    $clustersRoot = app_path('Filament/Clusters');

    if (! is_dir($clustersRoot)) {
        $this->markTestSkipped('No Filament clusters folder found');
    }

    // Collect all PHP files in app/ for searching usages
    $allPhpFiles = \Illuminate\Support\Facades\File::allFiles(app_path());

    // Walk clusters and find Pages/Widgets under Resources
    $finder = \Illuminate\Support\Facades\File::allFiles($clustersRoot);

    $orphans = [];

    foreach ($finder as $file) {
        $path = $file->getRealPath();

        // Only care about Pages and Widgets inside Resources
        if (! str_contains($path, DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR)) {
            continue;
        }

        if (! (str_contains($path, DIRECTORY_SEPARATOR . 'Pages' . DIRECTORY_SEPARATOR) || str_contains($path, DIRECTORY_SEPARATOR . 'Widgets' . DIRECTORY_SEPARATOR))) {
            continue;
        }

        $className = $file->getFilenameWithoutExtension();
        // Ignore obvious base names
        if (in_array($className, ['Page', 'Widget', 'ListPage', 'CreatePage', 'EditPage', 'List', 'Create', 'Edit'], true)) {
            continue;
        }

        $usageCount = 0;

        foreach ($allPhpFiles as $searchFile) {
            $content = file_get_contents($searchFile->getRealPath());

            // Count occurrences of the bare class name. This will include the
            // definition (one occurrence) and any registrations/usages elsewhere.
            if (str_contains($content, $className)) {
                $usageCount++;
            }

            if ($usageCount > 1) {
                break; // we have at least one external reference
            }
        }

        if ($usageCount <= 1) {
            $orphans[] = "{$className} (defined in {$file->getRelativePathname()})";
        }
    }
    if (! empty($orphans)) {
        $this->fail("DEAD CODE ALERT: Found orphan classes:\n" . implode("\n", $orphans));
    }

    $this->assertTrue(true);
});
