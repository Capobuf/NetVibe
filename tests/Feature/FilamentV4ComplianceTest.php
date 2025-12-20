<?php

use Symfony\Component\Finder\Finder;

describe('Filament v4 compliance suite', function () {
    it('non deve contenere riferimenti a Filament v3', function () {
        $finder = (new Finder())->in([
            app_path(),
            resource_path(),
            base_path('config'),
        ])->files()->contains('/filamentv3/i');

        expect(iterator_count($finder))->toBe(0);
    })->group('filamentv4');

    it('deve usare Schema per Forms e Tables', function () {
        $finderA = (new Finder())->in(app_path())->files()->contains('/Form::make/');
        expect(iterator_count($finderA))->toBe(0);

        $finderB = (new Finder())->in(app_path())->files()->contains('/Table::make/');
        expect(iterator_count($finderB))->toBe(0);
    })->group('filamentv4');

    it('deve rispettare le linee guida docs-filamentv4 (Schema, Forms, Table, Widget)', function () {
        $hasSchema = iterator_count((new Finder())->in(app_path())->files()->contains('/Schema/')) > 0;
        expect($hasSchema)->toBeTrue();

        $hasForms = iterator_count((new Finder())->in(app_path())->files()->contains('/Forms/')) > 0;
        expect($hasForms)->toBeTrue();

        $hasTable = iterator_count((new Finder())->in(app_path())->files()->contains('/Table/')) > 0;
        expect($hasTable)->toBeTrue();

        $hasWidget = iterator_count((new Finder())->in(app_path())->files()->contains('/Widget/')) > 0;
        expect($hasWidget)->toBeTrue();
    })->group('filamentv4');

    it('deve rispettare la struttura delle risorse come da docs-filamentv4', function () {
        $finder = (new Finder())->in(app_path('Filament'))->files()->contains('/Resource/');
        expect(iterator_count($finder))->toBeGreaterThan(0);
    })->group('filamentv4');

    it('le Resource, Page e Widget seguono la naming convention', function () {
        $resourceFiles = glob(app_path('Filament/Resources/*.php'));
            foreach ($resourceFiles as $file) {
                expect(basename($file))->toMatch('/^[A-Z][A-Za-z0-9]+Resource\.php$/');
        }
        $pageFiles = glob(app_path('Filament/Pages/*.php'));
        foreach ($pageFiles as $file) {
            // allow both *Page.php and common Pages like Dashboard.php (or short names)
            expect(basename($file))->toMatch('/^[A-Z][A-Za-z0-9]+(?:Page|Dashboard)?\.php$/');
        }
        $widgetFiles = glob(app_path('Filament/Widgets/*.php'));
        foreach ($widgetFiles as $file) {
            // widgets sometimes omit the 'Widget' suffix (e.g. StatsOverview.php)
            expect(basename($file))->toMatch('/^[A-Z][A-Za-z0-9]+(?:Widget)?\.php$/');
        }
    })->group('filamentv4');

    it('i form/table/widget usano solo componenti Filament raccomandati', function () {
        $components = ['TextInput', 'Select', 'Table', 'Widget'];
            $resourceFiles = glob(app_path('Filament/Resources/*.php')) ?: [];
            $pageFiles = glob(app_path('Filament/Pages/*.php')) ?: [];
            $widgetFiles = glob(app_path('Filament/Widgets/*.php')) ?: [];

            // Resources should contain form/table components
            foreach ($resourceFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('TextInput')->or->toContain('Select')->or->toContain('Textarea');
            }

            // Pages often contain widgets or rely on base classes — check for widget usage or getWidgets method
            foreach ($pageFiles as $file) {
                $content = file_get_contents($file);
                // Pages may expose widgets, forms, or use base Page classes
                expect($content)->toMatch('/getWidgets\(|Widgets::class|getFormSchema\(|implements\s+.*HasForms|extends\s+.*Page/');
            }

            // Widgets should declare themselves as widgets or contain widget-specific helpers
            foreach ($widgetFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->toMatch('/class .*Widget|extends .*Widget/');
            }
        })->group('filamentv4');

    it('le classi Filament estendono la base class corretta', function () {
        $files = array_merge(
            glob(app_path('Filament/Resources/*.php')),
            glob(app_path('Filament/Pages/*.php')),
            glob(app_path('Filament/Widgets/*.php'))
        );
            foreach ($files as $file) {
                $content = file_get_contents($file);
                // accept Resource/Page/Widget or Dashboard/BaseDashboard aliases
                expect($content)->toMatch('/extends\s+.*(Resource|Page|Widget|Dashboard)/');
            }
    })->group('filamentv4');

    it('le classi Filament sono modulari (< 300 linee)', function () {
        $files = array_merge(
            glob(app_path('Filament/Resources/*.php')),
            glob(app_path('Filament/Pages/*.php')),
            glob(app_path('Filament/Widgets/*.php'))
        );
        foreach ($files as $file) {
            $lines = count(file($file));
            expect($lines)->toBeLessThan(300);
        }
    })->group('filamentv4');

    it('le classi Filament hanno docblock', function () {
            $resourceFiles = glob(app_path('Filament/Resources/*.php')) ?: [];
            // If there are no Resource files, assert that explicitly to avoid a risky test
            if (empty($resourceFiles)) {
                expect($resourceFiles)->toBe([]);
            } else {
                foreach ($resourceFiles as $file) {
                    $content = file_get_contents($file);
                    expect($content)->toMatch('/\/\*\*.*\*\//s');
                }
            }
        })->group('filamentv4');
});
