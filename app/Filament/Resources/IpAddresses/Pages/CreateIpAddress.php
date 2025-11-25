<?php

namespace App\Filament\Resources\IpAddresses\Pages;

use App\Filament\Resources\IpAddresses\IpAddressResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIpAddress extends CreateRecord
{
    protected static string $resource = IpAddressResource::class;
}
