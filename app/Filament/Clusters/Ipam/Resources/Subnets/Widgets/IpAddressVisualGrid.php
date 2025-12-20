<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets\Widgets;

use App\Models\Subnet;
use App\Models\IpAddress;
use App\Services\IpConversionService;
use Filament\Widgets\Widget;
use IPLib\Factory;

class IpAddressVisualGrid extends Widget
{
    // Use a view path matching the cluster + resource namespace so Livewire auto-discovery works
    protected string $view = 'filament.clusters.ipam.resources.subnets.widgets.ip-address-visual-grid';

    public ?Subnet $record = null;

    public array $cells = [];
    public bool $renderGrid = false;
    public int $totalHosts = 0;

    protected int | string | array $columnSpan = 'full';

    public function mount(): void
    {
        if (! $this->record) {
            return;
        }

        $mask = (int) $this->record->mask;

        // Performance guard: do not render grids for very large subnets
        if ($mask < 22) {
            $this->renderGrid = false;
            return;
        }

        $ipService = app(IpConversionService::class);

        // resolve textual cidr
        $subnetAddress = $ipService->fromDecimal($this->record->subnet);
        if (! $subnetAddress) {
            $this->renderGrid = false;
            return;
        }

        $cidr = $subnetAddress->toString() . '/' . $mask;
        $range = Factory::parseRangeString($cidr);
        if (! $range) {
            $this->renderGrid = false;
            return;
        }

        // obtain start/end addresses
        $startAddress = method_exists($range, 'getStartAddress') ? $range->getStartAddress() : ($range->getStart() ?? null);
        $endAddress = method_exists($range, 'getEndAddress') ? $range->getEndAddress() : ($range->getEnd() ?? null);

        if (! $startAddress || ! $endAddress) {
            $this->renderGrid = false;
            return;
        }

        $startIp = (string) $startAddress->toString();
        $endIp = (string) $endAddress->toString();

        // Skip network + broadcast for standard IPv4 ranges when calculating cells
        $startDec = $ipService->toDecimal($startIp);
        $endDec = $ipService->toDecimal($endIp);

        // first/last host (skip network & broadcast)
        $firstHost = bcadd((string) $startDec, '1');
        $lastHost = bcsub((string) $endDec, '1');

        if (bccomp($firstHost, $lastHost) > 0) {
            $this->renderGrid = false;
            return;
        }

        // Compute total hosts using IpConversionService utility
        $this->totalHosts = (int) $ipService->calculateUsableHosts($mask);

        // Safety: limit rendering to small-ish networks (<= /22 => <= 1024 hosts)
        if ($this->totalHosts > 1024) {
            $this->renderGrid = false;
            return;
        }

        // Fetch existing IpAddress rows for the subnet
        $existing = IpAddress::where('subnet_id', $this->record->getKey())
            ->get(['id', 'ip_addr', 'ip_str', 'hostname', 'state', 'type'])
            ->keyBy('ip_addr')
            ->toArray();

        $cells = [];

        $current = $firstHost;
        while (bccomp((string) $current, (string) $lastHost) <= 0) {
            $key = (string) $current;
            $ipStr = $ipService->fromDecimal($key)?->toString();

            if (isset($existing[$key])) {
                $row = $existing[$key];
                $cells[] = [
                    'decimal' => $key,
                    'ip' => $ipStr,
                    'exists' => true,
                    'id' => $row['id'],
                    'type' => $row['type'] ?? 'host',
                    'hostname' => $row['hostname'] ?? null,
                    'state' => $row['state'] ?? 0,
                ];
            } else {
                $cells[] = [
                    'decimal' => $key,
                    'ip' => $ipStr,
                    'exists' => false,
                ];
            }

            $current = bcadd((string) $current, '1');
        }

        $this->cells = $cells;
        $this->renderGrid = true;
    }
}
