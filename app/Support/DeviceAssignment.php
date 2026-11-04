<?php

namespace App\Support;

use App\Models\Device;
use App\Models\IpAddress;
use Illuminate\Validation\ValidationException;

class DeviceAssignment
{
    public static function labelSql(): string
    {
        // Model connector labels live in the panel; resolve only the assigned connector.
        return <<<'SQL'
            (SELECT CONCAT(devices.name, IF(device_interfaces.id IS NULL, '', CONCAT('@',
                COALESCE(NULLIF(device_interfaces.name, ''),
                    (SELECT JSON_UNQUOTE(JSON_EXTRACT(mikrotik_sources.data, '$.name')) FROM mikrotik_sources
                        WHERE mikrotik_sources.device_interface_id = device_interfaces.id
                        AND mikrotik_sources.device_id = devices.id AND mikrotik_sources.kind = 'interface'
                        AND mikrotik_sources.problem IS NULL LIMIT 1),
                    (SELECT NULLIF(port.label, '') FROM JSON_TABLE(COALESCE(device_models.panel, JSON_OBJECT()),
                        '$.faces.*.ports[*]' COLUMNS (
                            id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PATH '$.id',
                            label VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PATH '$.label')) AS port
                        WHERE port.id = device_interfaces.connector_id LIMIT 1),
                    CONCAT('Porta ', LEFT(device_interfaces.connector_id, 8)), 'Interfaccia'))))
             FROM devices
             LEFT JOIN device_interfaces ON device_interfaces.id = ip_addresses.device_interface_id AND device_interfaces.device_id = devices.id
             LEFT JOIN device_models ON device_models.id = devices.device_model_id
             WHERE devices.id = ip_addresses.device_id)
            SQL;
    }

    // Call inside the company-locked transaction, shared with device/interface deletion.
    /** @return array{device_id: ?int, device_interface_id: ?int} */
    public static function validate(int $siteId, ?int $deviceId, ?int $interfaceId, ?IpAddress $current = null): array
    {
        $device = $deviceId ? Device::query()->where('site_id', $siteId)->find($deviceId) : null;
        if ($deviceId && ! $device) {
            throw ValidationException::withMessages(['device_id' => 'Scegli un dispositivo di questa sede.']);
        }
        if ($interfaceId && (! $device || ! $device->interfaces()->whereKey($interfaceId)->exists())) {
            throw ValidationException::withMessages(['device_interface_id' => 'Scegli un’interfaccia del dispositivo selezionato.']);
        }
        if ($current?->device_id && (! $deviceId || ($current->device_id === $deviceId && $current->device_interface_id && ! $interfaceId))) {
            throw ValidationException::withMessages([
                $deviceId ? 'device_interface_id' : 'device_id' => 'Per rimuovere l’associazione, usa «Libera IP» e conferma la cancellazione di tutti i dati dell’IP.',
            ]);
        }

        return ['device_id' => $deviceId, 'device_interface_id' => $interfaceId];
    }
}
