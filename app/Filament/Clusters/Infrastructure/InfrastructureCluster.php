<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure;

use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

class InfrastructureCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Infrastructure';

    protected static ?int $navigationSort = 25;
}
