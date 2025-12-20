<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Models\Subnet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextStore;
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
        $store = app(TenantContextStore::class);

        // Fast-fail: scanning must always be bound to a specific customer.
        if (blank($this->subnet->customer_id)) {
            throw new \RuntimeException('Subnet scanning requires customer_id.');
        }

        $store->set(new TenantContext(
            customerId: (int) $this->subnet->customer_id,
            locationId: $this->subnet->location_id ? (int) $this->subnet->location_id : null,
            actorType: 'queue',
            actorId: 'subnet:' . (string) $this->subnet->getKey(),
        ));

        $cidr = $this->subnet->cidr;

        // Guard: respect per-subnet discover flag — if discovery is disabled, skip.
        if (! $this->subnet->discover_subnet) {
            Log::info('ScanSubnetJob: discovery disabled for subnet', ['subnet_id' => $this->subnet->getKey(), 'cidr' => $cidr]);
            $store->clear();
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

                $seenAt = now();

                $ipModel = IpAddress::updateOrCreate(
                    ['subnet_id' => $this->subnet->getKey(), 'ip_addr' => (string) $decimal],
                    ['last_seen' => $seenAt, 'state' => 1]
                );

                // Restore lifecycle to ACTIVE when seen.
                $ipModel->markSeen($seenAt);
                $ipModel->save();

                $count++;
            }

            $this->subnet->last_discovery = now();
            $this->subnet->save();

            Log::info('Scanned subnet', ['cidr' => $cidr, 'found' => $count, 'subnet_id' => $this->subnet->getKey()]);
        } catch (\Throwable $e) {
            Log::error('ScanSubnetJob failed', ['cidr' => $cidr, 'error' => $e->getMessage()]);
            throw $e; // allow retry/failure handling
        } finally {
            // Important for long-running queue workers processing multiple jobs in the same process.
            $store->clear();
        }
    }
}
