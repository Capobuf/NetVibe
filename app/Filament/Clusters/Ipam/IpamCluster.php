<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam;

use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

class IpamCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'IPAM';

    protected static ?int $navigationSort = 5;
}
