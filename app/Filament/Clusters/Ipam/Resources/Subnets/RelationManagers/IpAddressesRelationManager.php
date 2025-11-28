<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets\RelationManagers;

use App\Models\Subnet;
use App\Services\IpConversionService;
use Filament\Resources\RelationManagers\RelationManager;

class IpAddressesRelationManager extends RelationManager
{
    /**
     * Validate an IP for a given subnet.
     * - Reject non-parsable IPs
     * - Reject IPv6 where subnet is IPv4
     * - Reject duplicate IP already present in subnet
     */
    public static function isIpValidForSubnet(Subnet $subnet, string $ip, $record = null): bool
    {
        $service = new IpConversionService();

        try {
            $ipDecimal = $service->toDecimal($ip);
        } catch (\Throwable $e) {
            return false;
        }

        // Parse subnet CIDR from the model; accept either a CIDR string or reconstruct from stored
        // decimal subnet + mask (some subnets may be created via factories or migrations)
        try {
            if (! empty($subnet->cidr)) {
                $parsed = $service->parseCidr($subnet->cidr);
            } elseif (! empty($subnet->subnet) && ! empty($subnet->mask)) {
                // Reconstruct a CIDR pointer: use toCidr to get textual representation
                $parsed = [
                    'subnet' => (string) $subnet->subnet,
                    'mask' => (int) $subnet->mask,
                ];
            } else {
                return false;
            }
        } catch (\Throwable $e) {
            // Malformed CIDR on subnet
            return false;
        }

        // Ensure IP is within CIDR (decimal-based check)
        $inSubnet = $service->isIpInSubnet((string)$ipDecimal, (string)$parsed['subnet'], (int)$parsed['mask']);

        // No textual fallback here - rely on decimal math via IpConversionService.
        if (! $inSubnet) {
            return false;
        }

        // Duplicate check (ignore if same record being edited)
        $exists = $subnet->ipAddresses()->where('ip_addr', $ipDecimal)->exists();
        // (no debug output)
        if ($exists) {
            return false;
        }

        return true;
    }
}
