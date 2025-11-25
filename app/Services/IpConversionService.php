<?php

namespace App\Services;

use IPLib\Factory;
use IPLib\Address\AddressInterface;

class IpConversionService
{
    /**
     * Convert IP address string to decimal format for database storage
     */
    public function toDecimal(string $ip): string
    {
        $address = Factory::parseAddressString($ip);
        
        if (!$address) {
            throw new \InvalidArgumentException("Invalid IP address: {$ip}");
        }
        
        // Convert bytes to decimal string using BCMath
        $decimal = '0';
        foreach ($address->getBytes() as $byte) {
            $decimal = bcadd(bcmul($decimal, '256'), (string)$byte);
        }
        
        return $decimal;
    }

    /**
     * Convert decimal IP address to human-readable string
     */
    /**
     * Convert decimal IP address to human-readable string
     */
    public function fromDecimal(string|int|float $decimal): ?AddressInterface
    {
        if (!$decimal) {
            return null;
        }

        // Convert to string to ensure BCMath handles it correctly for very large numbers
        $decimal = (string) $decimal;

        // IPv4 max value: 4294967295 (2^32 - 1)
        // If the value fits in IPv4 range, convert to dotted notation and parse
        if (function_exists('bccomp') && bccomp($decimal, '4294967295') <= 0) {
            // Use (int) cast to get a numeric value suitable for long2ip.
            // On 32-bit platforms casting may overflow, but bccomp above ensures it's in range.
            $ipString = long2ip((int) $decimal);
            if ($ipString === false || $ipString === null) {
                return null;
            }

            return Factory::parseAddressString($ipString);
        }

        // For now we don't attempt to reconstruct IPv6 from a decimal string.
        // Returning null avoids throwing errors when encountering large integers
        // that represent IPv6 addresses stored as decimals.
        return null;
    }

    /**
     * Parse CIDR notation to subnet and mask
     */
    public function parseCidr(string $cidr): array
    {
        if (!str_contains($cidr, '/')) {
            throw new \InvalidArgumentException("Invalid CIDR format: {$cidr}");
        }
        
        [$ip, $mask] = explode('/', $cidr);
        
        return [
            'subnet' => $this->toDecimal($ip),
            'mask' => (int) $mask,
        ];
    }

    /**
     * Convert decimal subnet + mask to CIDR notation
     */
    public function toCidr(string $subnet, int $mask): string
    {
        $address = $this->fromDecimal($subnet);
        $ip = $address ? $address->toString() : '0.0.0.0';
        return "{$ip}/{$mask}";
    }

    /**
     * Calculate number of usable hosts in a subnet
     */
    public function calculateUsableHosts(int $mask, bool $isIpv6 = false): string
    {
        $totalBits = $isIpv6 ? 128 : 32;
        $hostBits = $totalBits - $mask;
        
        if ($hostBits <= 0) {
            return '0';
        }
        
        // Total addresses = 2^hostBits
        $totalAddresses = bcpow('2', (string)$hostBits);
        
        // Usable = total - 2 (network + broadcast) for IPv4
        // For IPv6, all addresses are usable
        if (!$isIpv6 && $mask < 31) {
            $totalAddresses = bcsub($totalAddresses, '2');
        }
        
        return $totalAddresses;
    }

    /**
     * Check if an IP address is within a subnet
     */
    public function isIpInSubnet(string $ipDecimal, string $subnetDecimal, int $mask): bool
    {
        $ipAddress = $this->fromDecimal($ipDecimal);
        $subnetAddress = $this->fromDecimal($subnetDecimal);
        
        if (!$ipAddress || !$subnetAddress) {
            return false;
        }
        
        $range = Factory::parseRangeString($subnetAddress->toString() . '/' . $mask);
        
        if (!$range) {
            return false;
        }
        
        return $range->contains($ipAddress);
    }
}
