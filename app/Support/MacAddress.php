<?php

namespace App\Support;

class MacAddress
{
    /**
     * Canonical form (AA:BB:CC:DD:EE:FF) of any format the `mac_address` rule accepts
     * (colon, dash or Cisco dotted, any case). Other values are returned unchanged.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || filter_var($value, FILTER_VALIDATE_MAC) === false) {
            return $value;
        }

        return implode(':', str_split(strtoupper(preg_replace('/[^0-9a-f]/i', '', $value)), 2));
    }
}
