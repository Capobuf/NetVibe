<?php

use App\Support\SnipeItSync;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('snipe-it:sync', function (SnipeItSync $synchronizer) {
    $failed = false;
    foreach (App\Models\SnipeItSync::whereNotNull('activated_at')->with('company')->lazyById() as $sync) {
        if (! $sync->isActive()) {
            continue;
        }
        try {
            $synchronizer->refresh($sync->company);
        } catch (ValidationException $e) {
            $failed = true;
            $this->error('Azienda '.$sync->company_id.': '.collect($e->errors())->flatten()->first());
        }
    }

    return $failed ? 1 : 0;
})->purpose('Sincronizza le aziende Snipe-IT attivate');

Schedule::command('snipe-it:sync')->hourly()->withoutOverlapping();
