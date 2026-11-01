<?php

namespace App\Support;

class MikrotikPortMap
{
    public static function type(string $name): ?string
    {
        return match (true) {
            preg_match('/^ether[1-9][0-9]*$/', $name) === 1 => 'rj45',
            preg_match('/^(?:sfp|sfpplus|sfp-sfpplus|sfp28-)[1-9][0-9]*$/', $name) === 1 => 'sfp',
            str_starts_with($name, 'qsfp') => 'qsfp',
            default => null,
        };
    }

    /** @return array<string, string> Connector ID => RouterOS factory name. */
    public static function resolve(array $ports, array $names): array
    {
        $networkPorts = collect($ports)->whereIn('type', ['rj45', 'sfp', 'qsfp']);
        $reserved = $networkPorts->pluck('mikrotikName')->filter()->all();
        $proposals = [];
        foreach ($networkPorts as $port) {
            if (array_key_exists('mikrotikName', $port)) {
                $candidates = $port['mikrotikName'] === null ? [] : [$port['mikrotikName']];
            } else {
                $label = trim($port['label']);
                $candidates = [$label];
                if ($port['type'] === 'rj45' && preg_match('/^(?:ether)?([1-9][0-9]*)$/i', $label, $match)) {
                    $candidates = ['ether'.$match[1]];
                } elseif ($port['type'] === 'sfp' && preg_match('/^(?:sfp[- ]?)?([1-9][0-9]*)$/i', $label, $match)) {
                    $candidates = ['sfp'.$match[1], 'sfpplus'.$match[1], 'sfp-sfpplus'.$match[1], 'sfp28-'.$match[1]];
                } elseif ($label === '' && $networkPorts->where('type', $port['type'])->count() === 1) {
                    $candidates = array_values(array_filter($names, fn ($name) => self::type($name) === $port['type']));
                }
            }
            $available = array_key_exists('mikrotikName', $port) ? $names : array_diff($names, $reserved);
            $available = array_filter($available, fn ($name) => self::type($name) === $port['type']);
            $matches = array_values(array_intersect($available, $candidates));
            if (count($matches) === 1) {
                $proposals[$port['id']] = $matches[0];
            }
        }
        $counts = array_count_values($proposals);

        return array_filter($proposals, fn ($name) => $counts[$name] === 1);
    }
}
