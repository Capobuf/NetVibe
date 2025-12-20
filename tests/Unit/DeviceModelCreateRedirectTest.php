<?php

it('ensures all Filament Create pages override getRedirectUrl to handle previousUrl', function () {
    $dir = app_path('Filament');

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $class = 'App\\' . str_replace(DIRECTORY_SEPARATOR, '\\', substr($relative, 0, -4));

        if (! class_exists($class)) {
            // Try to require the file in case autoload hasn't loaded it yet.
            try {
                require_once $file->getPathname();
            } catch (Throwable $e) {
                continue;
            }
        }

        if (! class_exists($class)) {
            continue;
        }

        if (! is_subclass_of($class, \Filament\Resources\Pages\CreateRecord::class)) {
            continue;
        }

        $rm = new ReflectionMethod($class, 'getRedirectUrl');
        $declaring = $rm->getDeclaringClass()->getName();

        expect($declaring)->not()->toBe(\Filament\Resources\Pages\CreateRecord::class, "Class $class must override getRedirectUrl to honour previousUrl");
    }
});
