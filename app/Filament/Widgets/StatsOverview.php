<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\IpAddress;
use App\Models\Subnet;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Customers', Customer::count())
                ->description('Total Customers')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),
            
            Stat::make('Subnets', Subnet::count())
                ->description('Total Network Subnets')
                ->descriptionIcon('heroicon-m-server-stack')
                ->color('success'),
            
            Stat::make('IP Addresses', IpAddress::count())
                ->description('Total Assigned IPs')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('info'),
        ];
    }
}
