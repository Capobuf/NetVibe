<?php

namespace App\Support;

use App\Models\Device;
use Illuminate\Validation\ValidationException;

class DeviceAssignment
{
    // Call inside the company-locked transaction, shared with device/interface deletion.
    /** @return array{device_id: ?int, device_interface_id: ?int} */
    public static function validate(int $siteId, ?int $deviceId, ?int $interfaceId): array
    {
        $device = $deviceId ? Device::query()->where('site_id', $siteId)->find($deviceId) : null;
        if ($deviceId && ! $device) {
            throw ValidationException::withMessages(['device_id' => 'Scegli un dispositivo di questa sede.']);
        }
        if ($interfaceId && (! $device || ! $device->interfaces()->whereKey($interfaceId)->exists())) {
            throw ValidationException::withMessages(['device_interface_id' => 'Scegli un’interfaccia del dispositivo selezionato.']);
        }

        return ['device_id' => $deviceId, 'device_interface_id' => $interfaceId];
    }
}
