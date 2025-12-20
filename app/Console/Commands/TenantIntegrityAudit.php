<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant integrity audit.
 *
 * This command is intentionally "fast-fail": it returns a non-zero exit
 * code if any tenant-scoped table contains NULL customer_id, or if
 * cross-tenant mismatches are detected.
 */
final class TenantIntegrityAudit extends Command
{
    protected $signature = 'tenant:audit {--json : Output a JSON summary for CI pipelines}';

    protected $description = 'Audit tenant scoping integrity (NULL customer_id / cross-tenant mismatches / duplicates).';

    public function handle(): int
    {
        $issues = [];

        // Helper to safely check a table+column presence.
        $has = static function (string $table, string $column): bool {
            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        };

        // 1) NULL customer_id checks (customer-scoped tables).
        $nullChecks = [
            'scan_agents' => 'customer_id',
            'locations' => 'customer_id',
            'racks' => 'customer_id',
            'devices' => 'customer_id',
            'ports' => 'customer_id',
            'vlans' => 'customer_id',
            'subnets' => 'customer_id',
            'ip_addresses' => 'customer_id',
            'discovery_events' => 'customer_id',
            'vlan_blacklists' => 'customer_id',
            'subnet_blacklists' => 'customer_id',
        ];

        foreach ($nullChecks as $table => $column) {
            if (! $has($table, $column)) {
                continue;
            }

            $count = (int) DB::table($table)->whereNull($column)->count();
            if ($count > 0) {
                $issues[] = [
                    'type' => 'null_customer_id',
                    'table' => $table,
                    'count' => $count,
                    'details' => null,
                ];
            }
        }

        // 2) Cross-tenant mismatch checks.
        // ip_addresses.customer_id must match subnets.customer_id.
        if ($has('ip_addresses', 'customer_id') && $has('ip_addresses', 'subnet_id') && $has('subnets', 'customer_id')) {
            $count = (int) DB::table('ip_addresses')
                ->join('subnets', 'subnets.id', '=', 'ip_addresses.subnet_id')
                ->whereNotNull('ip_addresses.customer_id')
                ->whereColumn('ip_addresses.customer_id', '!=', 'subnets.customer_id')
                ->count();

            if ($count > 0) {
                $issues[] = [
                    'type' => 'customer_mismatch',
                    'table' => 'ip_addresses',
                    'count' => $count,
                    'details' => 'ip_addresses.customer_id != subnets.customer_id',
                ];
            }
        }

        // ports.customer_id must match devices.customer_id.
        if ($has('ports', 'customer_id') && $has('ports', 'device_id') && $has('devices', 'customer_id')) {
            $count = (int) DB::table('ports')
                ->join('devices', 'devices.id', '=', 'ports.device_id')
                ->whereNotNull('ports.customer_id')
                ->whereColumn('ports.customer_id', '!=', 'devices.customer_id')
                ->count();
            if ($count > 0) {
                $issues[] = [
                    'type' => 'customer_mismatch',
                    'table' => 'ports',
                    'count' => $count,
                    'details' => 'ports.customer_id != devices.customer_id',
                ];
            }
        }

        // 3) Duplicate checks (within tenant).
        // ip_addresses: duplicates by (customer_id, ip_addr)
        if ($has('ip_addresses', 'customer_id') && $has('ip_addresses', 'ip_addr')) {
            $dupes = DB::table('ip_addresses')
                ->select('customer_id', 'ip_addr', DB::raw('COUNT(*) as c'))
                ->whereNotNull('customer_id')
                ->groupBy('customer_id', 'ip_addr')
                ->having('c', '>', 1)
                ->limit(20)
                ->get();

            if ($dupes->count() > 0) {
                $issues[] = [
                    'type' => 'duplicates',
                    'table' => 'ip_addresses',
                    'count' => (int) $dupes->count(),
                    'details' => 'Duplicate (customer_id, ip_addr) groups (showing up to 20).',
                ];
            }
        }

        // vlans: duplicates by (customer_id, number)
        if ($has('vlans', 'customer_id') && $has('vlans', 'number')) {
            $dupes = DB::table('vlans')
                ->select('customer_id', 'number', DB::raw('COUNT(*) as c'))
                ->whereNotNull('customer_id')
                ->groupBy('customer_id', 'number')
                ->having('c', '>', 1)
                ->limit(20)
                ->get();

            if ($dupes->count() > 0) {
                $issues[] = [
                    'type' => 'duplicates',
                    'table' => 'vlans',
                    'count' => (int) $dupes->count(),
                    'details' => 'Duplicate (customer_id, number) groups (showing up to 20).',
                ];
            }
        }

        // subnets: duplicates by (customer_id, subnet, mask)
        if ($has('subnets', 'customer_id') && $has('subnets', 'subnet') && $has('subnets', 'mask')) {
            $dupes = DB::table('subnets')
                ->select('customer_id', 'subnet', 'mask', DB::raw('COUNT(*) as c'))
                ->whereNotNull('customer_id')
                ->groupBy('customer_id', 'subnet', 'mask')
                ->having('c', '>', 1)
                ->limit(20)
                ->get();

            if ($dupes->count() > 0) {
                $issues[] = [
                    'type' => 'duplicates',
                    'table' => 'subnets',
                    'count' => (int) $dupes->count(),
                    'details' => 'Duplicate (customer_id, subnet, mask) groups (showing up to 20).',
                ];
            }
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'ok' => count($issues) === 0,
                'issues' => $issues,
            ], JSON_PRETTY_PRINT));
        } else {
            if (count($issues) === 0) {
                $this->info('OK: No tenant integrity issues detected.');
            } else {
                $this->error('Tenant integrity issues detected (fast-fail).');
                $this->table(['type', 'table', 'count', 'details'], array_map(static fn ($i) => [
                    $i['type'],
                    $i['table'],
                    (string) $i['count'],
                    (string) ($i['details'] ?? ''),
                ], $issues));
            }
        }

        return count($issues) === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
