<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\Devices\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DeviceStatsOverview extends BaseWidget
{
    public static function canView(): bool
    {
        return true;
    }

    protected function getStats(): array
    {
        // Skeleton stats — placeholder values are safe defaults and will be
        // replaced by actual calculations later in Phase 2.
        return [
            Stat::make('total_ports')->title('Ports')->value('—'),
            Stat::make('up_ports')->title('Ports Up')->value('—')->color('success'),
            Stat::make('down_ports')->title('Ports Down')->value('—')->color('danger'),
        ];
    }
}
