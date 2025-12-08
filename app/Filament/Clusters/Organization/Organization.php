<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Organization;

use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

class Organization extends Cluster
{
    // Hide the cluster entry itself; resources should appear under the Organization dropdown
    protected static bool $shouldRegisterNavigation = false;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Organization';

    protected static ?int $navigationSort = 3;
}
