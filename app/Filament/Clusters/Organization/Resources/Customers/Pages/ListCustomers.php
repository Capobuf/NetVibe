<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Organization\Resources\Customers\Pages;

use App\Filament\Clusters\Organization\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make()
                ->label('New')
                ->icon('heroicon-o-plus')
                ->button()
                ->slideOver()
                ->modalWidth('md'),
        ];
    }
}
