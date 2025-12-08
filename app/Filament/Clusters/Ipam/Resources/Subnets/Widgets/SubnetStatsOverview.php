<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets\Widgets;

use App\Models\Subnet;
use App\Services\IpConversionService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SubnetStatsOverview extends BaseWidget
{
    public ?Subnet $record = null;

    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        if (! $this->record) {
            return [];
        }

        $ipService = app(IpConversionService::class);

        $mask = (int) $this->record->mask;
        $usable = (int) $ipService->calculateUsableHosts($mask);

        // Prefer eager-loaded withCount if available
        $used = $this->record->ip_addresses_count ?? \App\Models\IpAddress::where('subnet_id', $this->record->getKey())->count();

        $free = max(0, $usable - $used);

        $percent = $usable > 0 ? round(($used / $usable) * 100) : 0;

        return [
            Stat::make('Usable', $usable)
                ->description('Total usable hosts')
                ->descriptionIcon('heroicon-o-server-stack')
                ->color('primary'),

            Stat::make('Used', $used)
                ->description('Assigned to IPs')
                // Network icon is not available in our heroicons set -> use globe as network symbol (outline)
                ->descriptionIcon('heroicon-o-globe-alt')
                ->color('danger'),

            Stat::make('Free', $free)
                ->description('Available hosts')
                ->descriptionIcon('heroicon-o-check-badge')
                ->color('success'),

            Stat::make('Occupancy', $percent . '%')
                ->description('Subnet utilization')
                ->descriptionIcon('heroicon-o-chart-pie')
                ->color('warning'),
        ];
    }
}
