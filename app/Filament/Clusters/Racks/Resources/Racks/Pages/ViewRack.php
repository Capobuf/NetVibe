<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Racks\Resources\Racks\Pages;

use App\Filament\Clusters\Racks\Resources\Racks\RackResource;
use Filament\Resources\Pages\ViewRecord;

class ViewRack extends ViewRecord
{
    protected static string $resource = RackResource::class;

    /**
     * Display the RackVisualizerWidget in the page header and pass the
     * current record to it. This keeps the rendering purely Filament-native.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Clusters\Racks\Resources\Racks\Widgets\RackVisualizerWidget::make([
                'record' => $this->record,
            ]),
        ];
    }
}
