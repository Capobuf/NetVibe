<?php

namespace App\Support;

class Ipv4Cidr
{
    /** @return array{cidr: string, network_address: int, broadcast_address: int}|null */
    public static function parse(string $value): ?array
    {
        if (! preg_match('/\A([^\/]+)\/(0|[1-9]|[12][0-9]|3[0-2])\z/', $value, $parts)
            || filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $prefix = (int) $parts[2];
        $size = 1 << (32 - $prefix);
        $network = intdiv(ip2long($parts[1]), $size) * $size;

        return [
            'cidr' => long2ip($network).'/'.$prefix,
            'network_address' => $network,
            'broadcast_address' => $network + $size - 1,
        ];
    }
}
