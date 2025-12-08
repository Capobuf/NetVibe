<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Models\Device;
use Illuminate\Support\Facades\Log;

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
            // Router wins: overwrite device-level visible fields with data from the router

            // Hostname from /system/identity 'name'
            if (! empty($identity['name'])) {
                $device->hostname = $identity['name'];
            }

            // Serial number mapping
            if (! empty($resource['serial-number'])) {
                $device->serial_number = $resource['serial-number'];
            } elseif (! empty($resource['serial'])) {
                $device->serial_number = $resource['serial'];
            }

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
