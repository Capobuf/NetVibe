<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\L2Domains\Pages;

use App\Filament\Clusters\Ipam\Resources\L2Domains\L2DomainResource;
use Filament\Resources\Pages\EditRecord;

class EditL2Domain extends EditRecord
{
    protected static string $resource = L2DomainResource::class;
}
