<?php

namespace App\Filament\Clusters\Racks;

use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

class RacksCluster extends Cluster
{
    // Hide cluster itself from navigation — resources will populate the 'Racks & Devices' group
    protected static bool $shouldRegisterNavigation = false;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;
    
    protected static ?string $navigationLabel = 'Racks & Devices';
    
    protected static ?int $navigationSort = 20;
}
