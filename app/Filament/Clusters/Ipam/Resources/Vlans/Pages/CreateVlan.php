<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Vlans\Pages;

use App\Filament\Clusters\Ipam\Resources\Vlans\VlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVlan extends CreateRecord
{
    protected static string $resource = VlanResource::class;
}
