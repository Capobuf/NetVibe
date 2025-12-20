<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LifecycleStatus;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\Port;
use App\Settings\DiscoverySettings;
use App\Tenancy\TenantContextStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

final class ApplySoftRetirePolicyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(DiscoverySettings $settings): void
    {
        // Ensure this job always runs globally, regardless of any leftover tenant context.
        try {
            app(TenantContextStore::class)->clear();
        } catch (\Throwable $_) {
            // no-op
        }

        if (! $settings->inventory_soft_retire_enabled) {
            Log::info('ApplySoftRetirePolicyJob: soft-retire disabled');
            return;
        }

        $staleDays = max(1, (int) $settings->inventory_stale_after_days);
        $retireDays = max(1, (int) $settings->inventory_retire_after_days);

        if ($retireDays < $staleDays) {
            throw new \RuntimeException('Soft-retire settings invalid: retire_after_days must be >= stale_after_days.');
        }

        $now = now();
        $staleThreshold = $now->copy()->subDays($staleDays);
        $retireThreshold = $now->copy()->subDays($retireDays);

        Log::info('ApplySoftRetirePolicyJob: starting', [
            'stale_after_days' => $staleDays,
            'retire_after_days' => $retireDays,
            'stale_threshold' => $staleThreshold->toDateTimeString(),
            'retire_threshold' => $retireThreshold->toDateTimeString(),
        ]);

        // IP addresses
        $ipStale = IpAddress::query()
            ->whereNotNull('last_seen')
            ->where('last_seen', '<', $staleThreshold)
            ->where('lifecycle_status', LifecycleStatus::Active->value)
            ->update([
                'lifecycle_status' => LifecycleStatus::Stale->value,
                'stale_at' => DB::raw('COALESCE(stale_at, CURRENT_TIMESTAMP)'),
                'retired_at' => null,
            ]);

        $ipRetired = IpAddress::query()
            ->whereNotNull('last_seen')
            ->where('last_seen', '<', $retireThreshold)
            ->where('lifecycle_status', '!=', LifecycleStatus::Retired->value)
            ->update([
                'lifecycle_status' => LifecycleStatus::Retired->value,
                'stale_at' => DB::raw('COALESCE(stale_at, CURRENT_TIMESTAMP)'),
                'retired_at' => DB::raw('COALESCE(retired_at, CURRENT_TIMESTAMP)'),
            ]);

        // Ports (exclude ports explicitly excluded from discovery)
        $portStale = Port::query()
            ->where(function ($q) {
                $q->whereNull('exclude_from_discovery')->orWhere('exclude_from_discovery', false);
            })
            ->whereNotNull('last_seen')
            ->where('last_seen', '<', $staleThreshold)
            ->where('lifecycle_status', LifecycleStatus::Active->value)
            ->update([
                'lifecycle_status' => LifecycleStatus::Stale->value,
                'stale_at' => DB::raw('COALESCE(stale_at, CURRENT_TIMESTAMP)'),
                'retired_at' => null,
            ]);

        $portRetired = Port::query()
            ->where(function ($q) {
                $q->whereNull('exclude_from_discovery')->orWhere('exclude_from_discovery', false);
            })
            ->whereNotNull('last_seen')
            ->where('last_seen', '<', $retireThreshold)
            ->where('lifecycle_status', '!=', LifecycleStatus::Retired->value)
            ->update([
                'lifecycle_status' => LifecycleStatus::Retired->value,
                'stale_at' => DB::raw('COALESCE(stale_at, CURRENT_TIMESTAMP)'),
                'retired_at' => DB::raw('COALESCE(retired_at, CURRENT_TIMESTAMP)'),
            ]);

        // Devices (needs per-row computation across multiple channels)
        $deviceUpdated = 0;
        Device::withoutTenantScope(fn () => Device::query()->chunkById(200, function ($devices) use ($staleThreshold, $retireThreshold, $now, &$deviceUpdated) {
            foreach ($devices as $device) {
                $last = $device->last_seen_overall;
                if (! $last) {
                    continue;
                }

                $originalStatus = $device->lifecycle_status;

                if ($last->lt($retireThreshold)) {
                    $device->markRetired($now);
                } elseif ($last->lt($staleThreshold)) {
                    $device->markStale($now);
                } else {
                    // restore to active if it was previously stale/retired
                    if ($device->lifecycle_status !== LifecycleStatus::Active) {
                        $device->markSeen($now);
                    }
                }

                if ($device->isDirty(['lifecycle_status', 'stale_at', 'retired_at'])) {
                    $device->save();
                    $deviceUpdated++;
                } elseif ($originalStatus !== $device->lifecycle_status) {
                    // safety net (should not happen)
                    $device->save();
                    $deviceUpdated++;
                }
            }
        }));

        Log::info('ApplySoftRetirePolicyJob: completed', [
            'ip_marked_stale' => $ipStale,
            'ip_marked_retired' => $ipRetired,
            'ports_marked_stale' => $portStale,
            'ports_marked_retired' => $portRetired,
            'devices_updated' => $deviceUpdated,
        ]);
    }
}
