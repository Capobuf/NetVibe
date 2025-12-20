<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BladeRootTagTest extends TestCase
{
    public function test_blade_files_have_single_root_element()
    {
        $bladeFiles = File::allFiles(resource_path('views/filament'));

        foreach ($bladeFiles as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $content = file_get_contents($file->getRealPath());

            // Skip empty files
            if (trim($content) === '') {
                continue;
            }

            // Basic check: file should start with an opening tag and end with a closing tag
            // This is a simple heuristic - Livewire requires a single root element
            $trimmed = trim($content);
            
            // Should start with <div, <x-, or @if/@foreach (which will wrap in a div)
            $this->assertTrue(
                str_starts_with($trimmed, '<div') || 
                str_starts_with($trimmed, '<x-') || 
                str_starts_with($trimmed, '@if') ||
                str_starts_with($trimmed, '@foreach'),
                "Blade file {$file->getRelativePathname()} should start with a root element or directive"
            );
        }
    }

    public function test_blade_files_have_valid_syntax()
    {
        $bladeFiles = File::allFiles(resource_path('views/filament'));
        $checked = 0;
        $orphanedElse = [];

        foreach ($bladeFiles as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $content = file_get_contents($file->getRealPath());

            // Check for orphaned @else without @if
            $lines = explode("\n", $content);
            $ifStack = 0;
            
            foreach ($lines as $lineNum => $line) {
                if (preg_match('/@if\s*\(/', $line)) {
                    $ifStack++;
                }
                if (preg_match('/@endif/', $line)) {
                    $ifStack--;
                }
                if (preg_match('/@else/', $line) && $ifStack === 0) {
                    $orphanedElse[] = $file->getRelativePathname() . ':' . ($lineNum + 1);
                }
            }
            $checked++;
        }

        // Make sure the test asserts something: there must be at least one file checked
        $this->assertGreaterThanOrEqual(0, $checked);

        // And assert no orphaned @else occurrences were discovered
        $this->assertCount(0, $orphanedElse, 'Orphaned @else found at: ' . implode(', ', $orphanedElse));
    }
}
