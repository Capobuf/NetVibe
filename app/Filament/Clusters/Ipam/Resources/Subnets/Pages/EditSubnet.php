<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets\Pages;

use App\Filament\Clusters\Ipam\Resources\Subnets\SubnetResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditSubnet extends EditRecord
{
    protected static string $resource = SubnetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Subnet')
                ->modalDescription('This will permanently delete this subnet and all related metadata. This action cannot be undone.')
                ->color('danger'),
        ];
    }

    /**
     * Override relation managers for the Edit page. We intentionally return an
     * empty array so relation managers (eg. IpAddresses) are not shown on the
     * subnet edit screen while still being available on the View page.
     */
    public function getRelationManagers(): array
    {
        return [];
    }
}
