<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Models\Subnet;
use App\Services\IpConversionService;
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

    public function handle(FpingWrapper $wrapper, IpConversionService $converter): void
    {
        $cidr = $this->subnet->cidr;

        try {
            $found = $wrapper->scan($cidr);

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
