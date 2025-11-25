<?php

namespace App\Filament\Resources\Vrves\Pages;

use App\Filament\Resources\Vrves\VrfResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVrf extends EditRecord
{
    protected static string $resource = VrfResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
