<?php

namespace Tests\Unit;

use Tests\TestCase;

class BladeRootTagTest extends TestCase
{
    /**
     * Ensure Blade views used by Livewire/Filament start with a single HTML root element
     * (i.e. do not start with an HTML comment or stray whitespace/comments before the root).
     */
    public function test_blade_views_do_not_start_with_html_comment()
    {
        $paths = [];

        // Only check view folders that are usually rendered via Livewire/Filament (ignore full-page layouts like welcome)
        $directories = [resource_path('views/filament'), resource_path('views/livewire'), resource_path('views/components')];

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        $badFiles = [];

        foreach ($paths as $file) {
            $content = file_get_contents($file);

            // strip leading php open tags and whitespace
            $trimmed = preg_replace('/^\s+|^\\s*<\?php.*?\?>/s', '', $content);

            // find the first non-empty, non-whitespace line
            $lines = preg_split('/\R/', $trimmed);
            $firstNonEmpty = '';
            foreach ($lines as $line) {
                if (trim($line) !== '') {
                    $firstNonEmpty = trim($line);
                    break;
                }
            }

            if ($firstNonEmpty === '') {
                continue; // empty file - not our concern
            }

            // If first non-empty starts with an HTML comment, mark as bad
            if (str_starts_with($firstNonEmpty, '<!--')) {
                $badFiles[] = $file;
                continue;
            }

            // If first non-empty contains two sibling roots at top-level (quick heuristic):
            // check if file content starts with two top-level tags without single wrapper.
            // We'll consider files that start with a single root tag ok (starting with '<' followed by a tag)
            if (! preg_match('/^<\/?[a-zA-Z0-9\-:]+/', ltrim($firstNonEmpty))) {
                // not starting with a tag-like element -> flag
                $badFiles[] = $file;
            }
        }

        $this->assertEmpty($badFiles, 'Blade files with problematic leading content found: ' . implode(', ', $badFiles));
    }
}
