<?php

namespace App\Services;

use IPLib\Factory;
use IPLib\Address\AddressInterface;

class IpConversionService
{
    /**
     * Convert IP address string to decimal format for database storage
     * 
     * @param string $ip IP address (e.g., "192.168.1.1" or "2001:db8::1")
     * @return string Decimal representation
     */
    public function toDecimal(string $ip): string
    {
        $address = Factory::parseAddressString($ip);
        
        if (!$address) {
            throw new \InvalidArgumentException("Invalid IP address: {$ip}");
        }
        
        // Convert bytes to decimal string
        $bytes = $address->getBytes();
        return gmp_strval(gmp_import($bytes), 10);
    }

    /**
     * Convert decimal IP address to human-readable string
     * 
     * @param string $decimal Decimal IP address from database
     * @param bool $isIpv6 Whether the IP is IPv6 (optional, auto-detected)
     * @return string Human-readable IP (e.g., "192.168.1.1")
     */
    public function fromDecimal(string $decimal, bool $isIpv6 = null): string
    {
        // Auto-detect IPv6 if not specified (IPv6 decimals are much larger)
        if ($isIpv6 === null) {
            $isIpv6 = bccomp($decimal, '340282366920938463463374607431768211455') <= 0 
                   && bccomp($decimal, '65535') > 0;
        }
        
        $bytes = gmp_export(gmp_init($decimal, 10));
        
        // Pad bytes to correct length (4 for IPv4, 16 for IPv6)
        $expectedLength = $isIpv6 ? 16 : 4;
        $bytes = str_pad($bytes, $expectedLength, "\x00", STR_PAD_LEFT);
        
        $address = Factory::parseBytes($bytes);
        
        if (!$address) {
            throw new \InvalidArgumentException("Invalid decimal IP: {$decimal}");
        }
        
        return $address->toString();
    }

    /**
     * Parse CIDR notation to subnet and mask
     * 
     * @param string $cidr CIDR notation (e.g., "192.168.1.0/24")
     * @return array ['subnet' => decimal, 'mask' => int]
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
     * 
     * @param string $subnet Decimal subnet
     * @param int $mask CIDR mask
     * @return string CIDR notation (e.g., "192.168.1.0/24")
     */
    public function toCidr(string $subnet, int $mask): string
    {
        $ip = $this->fromDecimal($subnet);
        return "{$ip}/{$mask}";
    }

    /**
     * Calculate number of usable hosts in a subnet
     * 
     * @param int $mask CIDR mask
     * @param bool $isIpv6 Whether this is IPv6
     * @return string Number of usable hosts (as string for large IPv6 numbers)
     */
    public function calculateUsableHosts(int $mask, bool $isIpv6 = false): string
    {
        $totalBits = $isIpv6 ? 128 : 32;
        $hostBits = $totalBits - $mask;
        
        if ($hostBits <= 0) {
            return '0';
        }
        
        // Total addresses = 2^hostBits
        $totalAddresses = gmp_pow(2, $hostBits);
        
        // Usable = total - 2 (network + broadcast) for IPv4
        // For IPv6, all addresses are usable
        if (!$isIpv6 && $mask < 31) {
            $totalAddresses = gmp_sub($totalAddresses, 2);
        }
        
        return gmp_strval($totalAddresses);
    }

    /**
     * Check if an IP address is within a subnet
     * 
     * @param string $ipDecimal IP address in decimal
     * @param string $subnetDecimal Subnet in decimal
     * @param int $mask CIDR mask
     * @return bool True if IP is in subnet
     */
    public function isIpInSubnet(string $ipDecimal, string $subnetDecimal, int $mask): bool
    {
        $ip = Factory::parseAddressString($this->fromDecimal($ipDecimal));
        $subnet = Factory::parseRangeString($this->toCidr($subnetDecimal, $mask));
        
        if (!$ip || !$subnet) {
            return false;
        }
        
        return $subnet->contains($ip);
    }
}
