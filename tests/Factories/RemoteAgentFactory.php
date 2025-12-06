<?php

declare(strict_types=1);

namespace Tests\Factories;

/**
 * Small factory helpers for Remote Agent tests.
 */
final class RemoteAgentFactory
{
    /**
     * Given an array of CIDR strings, returns a JSON string compatible with
     * the agent config endpoint: { "subnets": [{"id":1,"cidr":"..."}, ...] }
     *
     * Example: RemoteAgentFactory::makeSubnets(['192.168.1.0/24'])
     */
    public static function makeSubnets(array $cidrs): string
    {
        $payload = ['subnets' => []];

        foreach ($cidrs as $i => $cidr) {
            $payload['subnets'][] = [
                'id' => $i + 1,
                'cidr' => $cidr,
                'vlan_id' => null,
                'description' => 'test subnet ' . ($i + 1),
            ];
        }

        return json_encode($payload);
    }
}
