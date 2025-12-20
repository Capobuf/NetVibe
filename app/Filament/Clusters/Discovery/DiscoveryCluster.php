<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery;

use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

class DiscoveryCluster extends Cluster
{
    // Do not register the cluster itself in navigation — resources will show inside the Discovery dropdown
    protected static bool $shouldRegisterNavigation = false;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Discovery';

    protected static ?int $navigationSort = 30;
}
