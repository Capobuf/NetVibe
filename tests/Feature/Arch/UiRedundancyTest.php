<?php

/**
 * Detect duplicate CreateAction instances placed in Resource table headerActions.
 * Enforce a single source of truth: CreateAction should exist on the ListPage
 * header (getHeaderActions()) and not inside the Resource::table() headerActions.
 */

test('resources do not duplicate create action in table header', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path('Filament/Clusters'));

    foreach ($files as $file) {
        // Only check Resource files
        if (! str_ends_with($file->getFilename(), 'Resource.php')) {
            continue;
        }

        $content = file_get_contents($file->getRealPath());

        // Look for ->headerActions([...CreateAction::make
        $hasTableHeaderCreate = preg_match('/->headerActions\s*\(\s*\[[^\]]*CreateAction::make/s', $content);

        if ($hasTableHeaderCreate) {
            $this->fail('UI Redundancy Detected: ' . $file->getFilename() . " defines CreateAction in the Table. Move it to the ListPage header.");
        }
    }

    $this->assertTrue(true);
});
