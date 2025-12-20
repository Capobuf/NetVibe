<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SetupController;

Route::middleware('web')->group(function () {
    Route::prefix('setup')->name('setup.')->controller(SetupController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/migrate', 'showMigrate')->name('migrate');
        Route::post('/migrate', 'runMigrate')->name('migrate.run');
        Route::get('/user', 'showUser')->name('user');
        Route::post('/user', 'storeUser')->name('user.store');

        // Step 4: first customer & default location
        Route::get('/customer', 'showCustomer')->name('customer');
        Route::post('/customer', 'storeCustomer')->name('customer.store');

        // Step 5: app URL / locale and final configuration
        Route::get('/app', 'showApp')->name('app');
        Route::post('/app', 'storeApp')->name('app.store');
    });
});

// =============================================================================
// DEBUG ROUTE - Remove in production
// =============================================================================

// Dev-only: quick mockup route to visually validate the rack layout in a browser
// (dev debug route removed during cleanup)
// NOTE: debug route removed

// debug route removed

// TEMP TEST: intentionally throw to exercise exception handling (remove after test)
Route::get('/_debug/throw', function () {
    if (! (app()->isLocal() || config('app.debug'))) {
        abort(404);
    }
    throw new \RuntimeException('test-exception');
});
 
// Temporary diagnostic endpoint: dumps exception handler callbacks so we can
// identify any render/report/throttle closures registered without parameters
// (these cause the runtime "The given Closure has no parameters." errors).
Route::get('/_debug/exception-callbacks', function () {
    if (! (app()->isLocal() || config('app.debug'))) {
        abort(404);
    }

    $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);

    // When using adapters (eg. collision) the application handler may be
    // wrapped. Attempt to unwrap to the foundation handler if possible.
    if ($handler instanceof \NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler) {
        try {
            $rp = new ReflectionProperty($handler, 'appExceptionHandler');
            $rp->setAccessible(true);
            $handler = $rp->getValue($handler);
        } catch (Throwable $_) {
            // ignore, continue with whatever we have
        }
    }

    $dump = [
        'handler_class' => get_class($handler),
        'renderCallbacks' => [],
        'reportCallbacks' => [],
        'throttleCallbacks' => [],
        'contextCallbacks' => [],
    ];

    foreach (['renderCallbacks', 'reportCallbacks', 'throttleCallbacks', 'contextCallbacks'] as $prop) {
        try {
            $rp = new ReflectionProperty($handler, $prop);
            $rp->setAccessible(true);
            $val = $rp->getValue($handler) ?? [];

            foreach ($val as $index => $cb) {
                $entry = ['index' => $index, 'type' => gettype($cb)];

                if ($cb instanceof Closure) {
                    try {
                        $rf = new ReflectionFunction($cb);
                        $entry['file'] = $rf->getFileName();
                        $entry['line'] = $rf->getStartLine();
                        $entry['param_count'] = count($rf->getParameters());
                        $entry['param_names'] = array_map(fn($p) => $p->getName(), $rf->getParameters());
                        $entry['param_types'] = array_map(function ($p) { return (string) ($p->getType()?->getName() ?? ''); }, $rf->getParameters());
                    } catch (Throwable $e) {
                        $entry['error'] = $e->getMessage();
                    }
                } elseif (is_array($cb) && isset($cb[0], $cb[1])) {
                    $entry['callable'] = is_object($cb[0]) ? get_class($cb[0]) : $cb[0];
                    $entry['method'] = $cb[1];
                }

                $dump[$prop][] = $entry;
            }
        } catch (Throwable $ex) {
            $dump[$prop] = ['error' => $ex->getMessage()];
        }
    }

    return response()->json($dump);
});
