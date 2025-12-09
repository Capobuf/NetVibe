<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Models\Device;
use Illuminate\Support\Facades\Log;
use App\Services\MergePolicyService;
use App\Enums\DiscoverySource;

class IngestMikrotikInventoryAction
{
    public function execute(?Device $device, array $data): void
    {
        // Placeholder: map inventory data to device fields and save
        Log::info('IngestMikrotikInventoryAction: ingesting inventory for device ' . ($device->id ?? 'null'));

        if (! $device) return;

        // Normalize resource and identity locations in the payload
        $resource = $data['resource'] ?? $data['system_resource'] ?? $data['result']['resource'] ?? [];
        $identity = $data['identity'] ?? $data['system_identity'] ?? $data['result']['identity'] ?? [];

        try {
            // Router wins: overwrite device-level visible fields con dati dal router,
            // passando comunque dal MergePolicyService per centralizzare la logica.
            $local = [
                'hostname' => $device->hostname,
                'serial_number' => $device->serial_number,
            ];

            $remote = [
                'hostname' => $identity['name'] ?? null,
                'serial_number' => $resource['serial-number'] ?? ($resource['serial'] ?? null),
            ];

            /** @var MergePolicyService $merge */
            $merge = app(MergePolicyService::class);
            $merge->mergeDevice($device, $local, $remote, DiscoverySource::Rest);

            // Device model mapping: board-name / platform
            $modelName = $resource['board-name'] ?? $resource['platform'] ?? $resource['model'] ?? null;
            if ($modelName) {
                // Create a DeviceModel record if not exists (brand= Mikrotik)
                $dm = \App\Models\DeviceModel::firstOrCreate([
                    'model' => (string) $modelName,
                ], [
                    'brand' => 'Mikrotik',
                    'type' => 'switch',
                ]);

                $device->device_model_id = $dm->id;
            }

            // OS / version and uptime — store into auth_extra under agent metadata (safe, encrypted cast)
            $extra = is_array($device->auth_extra) ? $device->auth_extra : [];
            if (! empty($resource['version'])) {
                $extra['os_version'] = $resource['version'];
            } elseif (! empty($resource['firmware'])) {
                $extra['os_version'] = $resource['firmware'];
            }

            if (! empty($resource['uptime'])) {
                $extra['uptime'] = $resource['uptime'];
            }

            $device->auth_extra = $extra;

            $device->save();
        } catch (\Throwable $e) {
            Log::warning('Failed to ingest inventory: ' . $e->getMessage());
        }
    }
}
