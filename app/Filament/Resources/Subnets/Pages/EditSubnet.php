<?php

namespace App\Filament\Resources\Subnets\Pages;

use App\Filament\Resources\Subnets\SubnetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubnet extends EditRecord
{
    protected static string $resource = SubnetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
