<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure;

use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

class InfrastructureCluster extends Cluster
{
    // Hide cluster navigation, show resources under the 'Infrastructure' group instead
    protected static bool $shouldRegisterNavigation = false;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Infrastructure';

    protected static ?int $navigationSort = 25;
}
