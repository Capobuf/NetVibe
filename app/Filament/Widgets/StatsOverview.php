<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\Device;
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
                ->descriptionIcon('heroicon-o-users')
                ->color('primary'),
            
            Stat::make('Subnets', Subnet::count())
                ->description('Total Network Subnets')
                ->descriptionIcon('heroicon-o-server-stack')
                ->color('success'),
            
            Stat::make('IP Addresses', IpAddress::count())
                ->description('Total Assigned IPs')
                ->descriptionIcon('heroicon-o-globe-alt')
                ->color('info'),

            Stat::make('Devices', Device::count())
                ->description(sprintf('Active %d / Total %d', Device::whereNotNull('ip_addr')->count(), Device::count()))
                ->descriptionIcon('heroicon-s-device-phone-mobile')
                ->color('secondary'),
        ];
    }
}
