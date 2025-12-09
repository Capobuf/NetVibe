<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * Base exporter che applica le convenzioni NetVibe:
 * - coda "low" per gli export async;
 * - messaggio di completamento generico in inglese.
 */
abstract class BaseExporter extends Exporter
{
    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Export job completed ✅. Your file is ready for download.';
    }

    public function getJobQueue(): ?string
    {
        // Tutti gli export girano sulla coda "low" come da PDL.
        return 'low';
    }
}

