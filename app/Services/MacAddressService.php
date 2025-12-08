<?php

declare(strict_types=1);

namespace App\Services;

/**
 * MAC Address Normalization Service
 * 
 * Normalizes MAC addresses to a consistent format (lowercase colon-separated)
 * to prevent duplicate entries and ensure topology link matching across vendors
 * (Mikrotik, Ubiquiti, Cisco may use different formats)
 */
final class MacAddressService
{
    /**
     * Normalize MAC address to lowercase colon-separated format: xx:xx:xx:xx:xx:xx
     * 
     * Handles various input formats:
     * - Uppercase/lowercase
     * - Colons, dashes, dots as separators
     * - No separators (continuous hex)
     * - Invalid formats return null
     * 
     * @param string|null $mac Raw MAC address from device payload
     * @return string|null Normalized MAC (xx:xx:xx:xx:xx:xx) or null if invalid
     */
    public static function normalize(?string $mac): ?string
    {
        if (!$mac) {
            return null;
        }

        // Trim whitespace
        $mac = trim($mac);

        // Remove all separators (: - .) and convert to lowercase
        $clean = strtolower(preg_replace('/[:.\-]/', '', $mac));

        // Validate: must be exactly 12 hexadecimal characters
        if (!preg_match('/^[a-f0-9]{12}$/', $clean)) {
            return null;  // Invalid MAC format
        }

        // Format as xx:xx:xx:xx:xx:xx (pairs separated by colons)
        return implode(':', str_split($clean, 2));
    }

    /**
     * Validate MAC address without normalization
     * Useful for filtering before processing
     * 
     * @param string|null $mac MAC address to validate
     * @return bool True if MAC is valid format
     */
    public static function isValid(?string $mac): bool
    {
        return self::normalize($mac) !== null;
    }

    /**
     * Compare two MAC addresses (handles different formats)
     * 
     * @param string|null $mac1 First MAC address (any format)
     * @param string|null $mac2 Second MAC address (any format)
     * @return bool True if both normalize to same value
     */
    public static function equals(?string $mac1, ?string $mac2): bool
    {
        $normalized1 = self::normalize($mac1);
        $normalized2 = self::normalize($mac2);

        if ($normalized1 === null || $normalized2 === null) {
            return false;
        }

        return $normalized1 === $normalized2;
    }
}
