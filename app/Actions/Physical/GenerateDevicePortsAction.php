<?php

declare(strict_types=1);

namespace App\Actions\Physical;

use App\Models\Device;
use App\Models\Port;

final class GenerateDevicePortsAction
{
    /**
     * Generate ports for the given device based on its device model counters.
     * Idempotent: will not duplicate ports that already exist (device_id + name check).
     */
    public function execute(Device $device): void
    {
        $model = $device->deviceModel;

        if (! $model) {
            return; // nothing to generate from
        }

        // Ethernet ports (rj45)
        $ethCount = (int) ($model->ethernet_ports ?? 0);
        for ($i = 1; $i <= $ethCount; $i++) {
            $name = sprintf('Eth%d', $i);
            Port::firstOrCreate([
                'device_id' => $device->id,
                'name' => $name,
            ], [
                'type' => 'rj45',
                'status' => 'down',
            ]);
        }

        // SFP ports
        $sfpCount = (int) ($model->sfp_ports ?? 0);
        for ($i = 1; $i <= $sfpCount; $i++) {
            $name = sprintf('SFP%d', $i);
            Port::firstOrCreate([
                'device_id' => $device->id,
                'name' => $name,
            ], [
                'type' => 'sfp',
                'status' => 'down',
            ]);
        }
    }
}
