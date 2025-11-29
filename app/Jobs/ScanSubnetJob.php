<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Models\Subnet;
use App\Services\IpConversionService;
use App\Settings\DiscoverySettings;
use App\Services\Scanners\FpingWrapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ScanSubnetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Subnet $subnet)
    {
    }

    public function handle(FpingWrapper $wrapper, IpConversionService $converter, DiscoverySettings $settings): void
    {
        $cidr = $this->subnet->cidr;

        // Guard: respect per-subnet discover flag — if discovery is disabled, skip.
        if (! $this->subnet->discover_subnet) {
            Log::info('ScanSubnetJob: discovery disabled for subnet', ['subnet_id' => $this->subnet->getKey(), 'cidr' => $cidr]);
            return;
        }

        try {
            $found = $wrapper->scan($cidr, $settings->icmp_timeout_ms, $settings->icmp_retries);

            $count = 0;
            foreach ($found as $ip) {
                // Convert to decimal string for db
                try {
                    $decimal = $converter->toDecimal($ip);
                } catch (\Throwable $e) {
                    // Skip invalid ip
                    Log::warning('ScanSubnetJob: skipping invalid ip', ['ip' => $ip, 'error' => $e->getMessage()]);
                    continue;
                }

                IpAddress::updateOrCreate(
                    ['subnet_id' => $this->subnet->getKey(), 'ip_addr' => (string) $decimal],
                    ['last_seen' => now(), 'state' => 1]
                );

                $count++;
            }

            $this->subnet->last_discovery = now();
            $this->subnet->save();

            Log::info('Scanned subnet', ['cidr' => $cidr, 'found' => $count, 'subnet_id' => $this->subnet->getKey()]);
        } catch (\Throwable $e) {
            Log::error('ScanSubnetJob failed', ['cidr' => $cidr, 'error' => $e->getMessage()]);
            throw $e; // allow retry/failure handling
        }
    }
}
