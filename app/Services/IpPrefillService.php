<?php

namespace App\Services;

use App\Models\Subnet;
use Illuminate\Support\Facades\DB;
use IPLib\Factory;

class IpPrefillService
{
    /**
     * Prefill all IP addresses for a given subnet into the ip_addresses table
     * Returns an array with 'inserted' count on success.
     *
     * Note: IPv6 is intentionally not supported by this method.
     */
    public function prefill(Subnet $subnet, int $chunkSize = 500): array
    {
        if ((int) $subnet->mask < 22) {
            throw new \InvalidArgumentException('Subnet troppo grande per il pre-fill');
        }

        $ipService = app(IpConversionService::class);

        $subnetAddress = $ipService->fromDecimal($subnet->subnet);
        if (! $subnetAddress) {
            throw new \RuntimeException('Impossibile risolvere la subnet');
        }

        $baseIp = (string) $subnetAddress->toString();

        if (str_contains($baseIp, ':')) {
            throw new \RuntimeException('IPv6 non supportato per il pre-fill');
        }

        $cidr = $baseIp . '/' . (int) $subnet->mask;
        $range = Factory::parseRangeString($cidr);

        if (! $range) {
            throw new \RuntimeException('CIDR non valido');
        }

        $startAddress = null;
        $endAddress = null;
        if (method_exists($range, 'getStartAddress')) {
            $startAddress = $range->getStartAddress();
            $endAddress = $range->getEndAddress();
        } elseif (method_exists($range, 'getStart')) {
            $startAddress = $range->getStart();
            $endAddress = $range->getEnd();
        }

        if (! $startAddress || ! $endAddress) {
            throw new \RuntimeException('Impossibile ottenere start/end range');
        }

        $startIp = (string) $startAddress->toString();
        $endIp = (string) $endAddress->toString();

        if (str_contains($startIp, ':') || str_contains($endIp, ':')) {
            throw new \RuntimeException('IPv6 non supportato per il pre-fill');
        }

        $startDec = $ipService->toDecimal($startIp);
        $endDec = $ipService->toDecimal($endIp);

        $buffers = [];
        $insertedCount = 0;
        $current = $startDec;

        // We skip the first (network) and last (broadcast) addresses for IPv4 ranges
        // e.g. for a /24 we will prefill .1 - .254
        $firstHost = bcadd((string) $startDec, '1');
        $lastHost = bcsub((string) $endDec, '1');

        // If the firstHost is greater than lastHost it means the subnet has no host addresses
        // (e.g. /31 or /32), so we should not insert anything.
        if (bccomp($firstHost, $lastHost) > 0) {
            return ['inserted' => 0];
        }

        $current = $firstHost;

        while (bccomp((string) $current, (string) $lastHost) <= 0) {
            $exists = DB::table('ip_addresses')
                ->where('subnet_id', $subnet->id)
                ->where('ip_addr', (string) $current)
                ->exists();

                if (! $exists) {
                    $now = now();
                    $buffers[] = [
                        'ip_addr' => (string) $current,
                        'subnet_id' => $subnet->id,
                        'state' => 0,
                        // leave last_seen empty (null) so UI shows 'Never' and users can populate later
                        'last_seen' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
            }

            if (count($buffers) >= $chunkSize) {
                DB::table('ip_addresses')->insertOrIgnore($buffers);
                $insertedCount += count($buffers);
                $buffers = [];
            }

            $current = bcadd((string) $current, '1');
        }

        if (count($buffers) > 0) {
            DB::table('ip_addresses')->insertOrIgnore($buffers);
            $insertedCount += count($buffers);
        }

        return ['inserted' => $insertedCount];
    }
}
