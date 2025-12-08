<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets\Widgets;

use App\Models\Subnet;
use App\Models\IpAddress;
use App\Enums\IpAddressType;
use App\Services\IpConversionService;
use Filament\Widgets\Widget;

class SubnetIpCompositionChart extends Widget
{
    protected string $view = 'filament.clusters.ipam.resources.subnets.widgets.subnet-ip-composition-chart';

    public ?Subnet $record = null;

    public array $chartData = [];

    protected int | string | array $columnSpan = 'full';

    public function mount(): void
    {
        if (! $this->record) {
            $this->chartData = [];
            return;
        }

        $ipService = app(IpConversionService::class);
        $mask = (int) $this->record->mask;
        $usable = (int) $ipService->calculateUsableHosts($mask);

        // Group counts by type
        $counts = IpAddress::where('subnet_id', $this->record->getKey())
            ->selectRaw('type, COUNT(*) as cnt')
            ->groupBy('type')
            ->pluck('cnt', 'type')
            ->toArray();

        $usedTotal = array_sum($counts);

        $free = max(0, $usable - $usedTotal);

        // prepare labels and data in a predictable order
        $labels = [];
        $data = [];
        $bg = [];

        $mapping = [
            IpAddressType::Gateway->value => ['label' => 'Gateway', 'color' => '#3b82f6'],
            IpAddressType::Reserved->value => ['label' => 'Reserved', 'color' => '#f59e0b'],
            IpAddressType::DHCP->value => ['label' => 'DHCP', 'color' => '#94a3b8'],
            IpAddressType::Host->value => ['label' => 'Hosts', 'color' => '#10b981'],
            IpAddressType::Broadcast->value => ['label' => 'Broadcast', 'color' => '#ef4444'],
        ];

        foreach ($mapping as $type => $meta) {
            $labels[] = $meta['label'];
            $data[] = (int) ($counts[$type] ?? 0);
            $bg[] = $meta['color'];
        }

        // add free segment if any capacity
        if ($usable > 0) {
            $labels[] = 'Free';
            $data[] = $free;
            $bg[] = '#9ca3af';
        }

        $this->chartData = [
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => $bg,
                    'hoverOffset' => 6,
                ],
            ],
            'options' => [
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => [
                        'position' => 'bottom',
                    ],
                ],
            ],
        ];
    }

    /**
     * Expose data to the blade template in a predictable shape
     * Filament will extract widget properties when rendering, but providing
     * a getData() makes the contract explicit for tests and templates.
     */
    public function getData(): array
    {
        return [
            'record' => $this->record,
            'chartData' => $this->chartData,
        ];
    }
}
