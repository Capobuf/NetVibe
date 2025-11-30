<?php

/**
 * Detect large duplicated blocks inside the same file to catch accidental
 * copy/paste mistakes introduced during refactors (e.g. AI-assisted edits).
 */

test('codebase does not contain massive duplication within files', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path());

    $windowSize = 6; // number of subsequent normalized lines to match

    foreach ($files as $file) {
        $path = $file->getRealPath();

        // Only inspect PHP files
        if (! str_ends_with($path, '.php')) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        $normalized = [];

        // Normalize lines, keep original line numbers
        foreach ($lines as $index => $line) {
            $trim = trim($line);

            // Skip noise
            if ($trim === '' || strlen($trim) < 3) {
                continue;
            }

            if (str_starts_with($trim, 'use ')) {
                continue;
            }

            if (str_starts_with($trim, '//') || str_starts_with($trim, '/*') || str_starts_with($trim, '*') || str_starts_with($trim, '*/')) {
                continue;
            }

            // Skip trivial brace or array punctuation lines
            if (in_array($trim, ['}', '{', '];', '],', 'return;'], true)) {
                continue;
            }

            // Normalized content used for matching (we do not alter content further aside from trimming)
            $normalized[] = [
                'line' => $index + 1,
                'content' => $trim,
            ];
        }

        $n = count($normalized);
        if ($n < $windowSize) {
            continue;
        }

        $hashes = [];

        for ($i = 0; $i <= $n - $windowSize; $i++) {
            $chunk = '';
            for ($j = 0; $j < $windowSize; $j++) {
                $chunk .= $normalized[$i + $j]['content'];
            }

            $hash = md5($chunk);

            if (isset($hashes[$hash])) {
                $originalLine = $hashes[$hash];
                $currentLine = $normalized[$i]['line'];

                // ignore immediate overlaps (often legitimate)
                if (abs($currentLine - $originalLine) > $windowSize) {
                    $this->fail(sprintf(
                        "Possible duplicate logic found in %s around lines %d and %d",
                        $file->getRelativePathname(),
                        $originalLine,
                        $currentLine
                    ));
                }
            } else {
                $hashes[$hash] = $normalized[$i]['line'];
            }
        }
    }

    // At least one assertion to keep Pest from marking the test risky
    $this->assertTrue(true);
});
