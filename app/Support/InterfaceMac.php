<?php

namespace App\Support;

use App\Models\DeviceInterface;
use App\Models\IpAddress;
use Illuminate\Validation\ValidationException;

class InterfaceMac
{
    // All callers hold the company lock, including association and interface writers.
    public static function synchronize(DeviceInterface $interface, ?string $mac, ?IpAddress $source, ?string $confirmation, ?string $mikrotikMode = null): ?string
    {
        $ips = $interface->ipAddresses()->orderBy('id')->lockForUpdate()->get();
        $sameInterface = $source?->exists && $source->device_interface_id === $interface->id;
        // An empty side inherits on association; an explicit edit can also clear a shared MAC.
        if ($source && (! $sameInterface || $source->mac_address === null)) {
            $mac ??= $interface->mac_address;
        }
        $different = fn (?string $value) => strcasecmp(str_replace('-', ':', $value ?? ''), str_replace('-', ':', $mac ?? '')) !== 0;
        $affected = $ips->filter(fn ($ip) => $ip->id !== $source?->id && $different($ip->mac_address));
        $conflict = $interface->mac_address && $different($interface->mac_address);
        if ($conflict || $affected->isNotEmpty() || $confirmation) {
            $state = [
                'interface' => $interface->only('id', 'device_id', 'mac_address'),
                'source' => $source?->only('id', 'subnet_id', 'address', 'device_id', 'device_interface_id', 'mac_address'),
                'ips' => $ips->map->only('id', 'address', 'mac_address')->all(),
                'mac' => $mac,
            ];
            $token = hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), config('app.key'));
            if (! is_string($confirmation) || ! hash_equals($token, $confirmation)) {
                $current = $interface->mac_address ?: 'non compilato';
                $next = $mac ?: 'non compilato';
                $message = "MAC interfaccia: {$current}. MAC da applicare: {$next}.";
                if ($affected->isNotEmpty()) {
                    $message .= ' Saranno aggiornati anche gli IP: '.$affected->map(fn ($ip) => long2ip($ip->address).' ('.($ip->mac_address ?: 'non compilato').')')->implode(', ').'.';
                }
                throw ValidationException::withMessages([
                    'mac_confirmation' => ($confirmation ? 'I dati sono cambiati. Verifica di nuovo. ' : '').$message,
                    'mac_confirmation_token' => $token,
                ]);
            }
        }
        foreach ($ips as $ip) {
            if ($ip->id === $source?->id) {
                continue;
            }
            MikrotikIpam::manualChange($ip, ['mac_address' => $mac], $mikrotikMode);
            UnifiIpam::manualChange($ip, ['mac_address' => $mac], $mikrotikMode);
            if ($ip->isDirty()) {
                $ip->save();
            }
        }
        $interface->update(['mac_address' => $mac]);
        $interface->ipAddresses()->update(['mac_address' => $mac]);

        return $mac;
    }
}
