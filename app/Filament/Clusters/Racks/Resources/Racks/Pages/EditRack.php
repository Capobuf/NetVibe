<?php

namespace App\Filament\Clusters\Racks\Resources\Racks\Pages;

use App\Filament\Clusters\Racks\Resources\Racks\RackResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditRack extends EditRecord
{
    protected static string $resource = RackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
