<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\IpAddresses\Pages;

use App\Filament\Clusters\Ipam\Resources\IpAddresses\IpAddressResource;
use Filament\Resources\Pages\ListRecords;

class ListIpAddresses extends ListRecords
{
    protected static string $resource = IpAddressResource::class;
}
