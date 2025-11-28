<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery;

use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

class DiscoveryCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Discovery';

    protected static ?int $navigationSort = 30;
}
