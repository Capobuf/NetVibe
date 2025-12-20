<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-001 — Tenancy & Ingestion Hardening (Step 6)
 *
 * Fast-fail DB hardening to prevent cross-tenant contamination.
 *
 * This migration:
 * - backfills customer_id deterministically where possible
 * - fails fast if data cannot be safely assigned
 * - enforces NOT NULL + sensible unique constraints
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->hardenSubnets();
        $this->hardenVlans();
        $this->hardenIpAddresses();
        $this->hardenRacks();
        $this->hardenDevices();
    }

    public function down(): void
    {
        // Intentionally non-reversible: these constraints are safety guarantees.
        // If you need to roll back, do so via a dedicated migration.
    }

    private function hardenSubnets(): void
    {
        if (! Schema::hasTable('subnets') || ! Schema::hasColumn('subnets', 'customer_id')) {
            return;
        }

        // Backfill from scan_agent_id when available (deterministic).
        if (Schema::hasColumn('subnets', 'scan_agent_id') && Schema::hasTable('scan_agents')) {
            DB::table('subnets')
                ->whereNull('subnets.customer_id')
                ->whereNotNull('subnets.scan_agent_id')
                ->join('scan_agents', 'scan_agents.id', '=', 'subnets.scan_agent_id')
                ->update(['subnets.customer_id' => DB::raw('scan_agents.customer_id')]);
        }

        // Backfill from vlan_id when available (only if vlan has a single customer).
        if (Schema::hasColumn('subnets', 'vlan_id') && Schema::hasTable('vlans')) {
            DB::table('subnets')
                ->whereNull('subnets.customer_id')
                ->whereNotNull('subnets.vlan_id')
                ->join('vlans', 'vlans.id', '=', 'subnets.vlan_id')
                ->whereNotNull('vlans.customer_id')
                ->update(['subnets.customer_id' => DB::raw('vlans.customer_id')]);
        }

        // Fast-fail if any subnet remains unassigned.
        $missing = DB::table('subnets')->whereNull('customer_id')->count();
        if ($missing > 0) {
            throw new RuntimeException("EPIC-001 Step 6: subnets.customer_id contains {$missing} NULL rows. Fix data before migrating.");
        }

        // Enforce NOT NULL for customer_id (fast-fail at DB level).
        $this->forceNotNull('subnets', 'customer_id');
        $this->rebindCustomerForeignKey('subnets');

        // Add a deterministic uniqueness constraint per customer: (customer_id, subnet, mask)
        if (Schema::hasColumn('subnets', 'subnet') && Schema::hasColumn('subnets', 'mask')) {
            $duplicates = DB::table('subnets')
                ->select('customer_id', 'subnet', 'mask', DB::raw('COUNT(*) as c'))
                ->groupBy('customer_id', 'subnet', 'mask')
                ->having('c', '>', 1)
                ->limit(1)
                ->get();

            if ($duplicates->isNotEmpty()) {
                throw new RuntimeException('EPIC-001 Step 6: duplicate subnets detected for (customer_id, subnet, mask). Resolve duplicates before adding unique constraint.');
            }

            try {
                Schema::table('subnets', function (Blueprint $table) {
                    $table->unique(['customer_id', 'subnet', 'mask'], 'subnets_customer_subnet_mask_unique');
                });
            } catch (Throwable $_) {
                // ignore if already exists
            }
        }
    }

    private function hardenVlans(): void
    {
        if (! Schema::hasTable('vlans') || ! Schema::hasColumn('vlans', 'customer_id')) {
            return;
        }

        // If any vlan has NULL customer_id, try to infer from subnets.vlan_id
        if (Schema::hasTable('subnets') && Schema::hasColumn('subnets', 'vlan_id') && Schema::hasColumn('subnets', 'customer_id')) {
            // Fast-fail on ambiguous mappings: a VLAN referenced by subnets from multiple customers.
            $ambiguous = DB::table('subnets')
                ->whereNotNull('subnets.vlan_id')
                ->whereNotNull('subnets.customer_id')
                ->select('subnets.vlan_id', DB::raw('COUNT(DISTINCT subnets.customer_id) as c'))
                ->groupBy('subnets.vlan_id')
                ->having('c', '>', 1)
                ->limit(1)
                ->get();

            if ($ambiguous->isNotEmpty()) {
                throw new RuntimeException('EPIC-001 Step 6: ambiguous VLAN→Customer mapping found (same vlan_id referenced by multiple customers). Fix before migrating.');
            }

            DB::table('vlans')
                ->whereNull('vlans.customer_id')
                ->whereIn('vlans.id', function ($q) {
                    $q->from('subnets')
                        ->select('vlan_id')
                        ->whereNotNull('vlan_id')
                        ->whereNotNull('customer_id')
                        ->groupBy('vlan_id');
                })
                ->update([
                    'customer_id' => DB::raw('(SELECT subnets.customer_id FROM subnets WHERE subnets.vlan_id = vlans.id AND subnets.customer_id IS NOT NULL LIMIT 1)')
                ]);
        }

        $missing = DB::table('vlans')->whereNull('customer_id')->count();
        if ($missing > 0) {
            throw new RuntimeException("EPIC-001 Step 6: vlans.customer_id contains {$missing} NULL rows. Assign VLANs to customers before migrating.");
        }

        $this->forceNotNull('vlans', 'customer_id');
        $this->rebindCustomerForeignKey('vlans');

        // Ensure uniqueness per customer for VLAN number.
        if (Schema::hasColumn('vlans', 'number')) {
            $duplicates = DB::table('vlans')
                ->select('customer_id', 'number', DB::raw('COUNT(*) as c'))
                ->groupBy('customer_id', 'number')
                ->having('c', '>', 1)
                ->limit(1)
                ->get();

            if ($duplicates->isNotEmpty()) {
                throw new RuntimeException('EPIC-001 Step 6: duplicate VLAN numbers detected per customer. Resolve duplicates before enforcing uniqueness.');
            }

            try {
                Schema::table('vlans', function (Blueprint $table) {
                    $table->unique(['customer_id', 'number'], 'vlans_customer_number_unique');
                });
            } catch (Throwable $_) {
                // ignore
            }
        }
    }

    private function hardenIpAddresses(): void
    {
        if (! Schema::hasTable('ip_addresses') || ! Schema::hasColumn('ip_addresses', 'customer_id')) {
            return;
        }

        // Deterministic backfill from subnets.customer_id
        if (Schema::hasColumn('ip_addresses', 'subnet_id') && Schema::hasTable('subnets') && Schema::hasColumn('subnets', 'customer_id')) {
            DB::table('ip_addresses')
                ->join('subnets', 'subnets.id', '=', 'ip_addresses.subnet_id')
                ->update(['ip_addresses.customer_id' => DB::raw('subnets.customer_id')]);

            $mismatch = DB::table('ip_addresses')
                ->join('subnets', 'subnets.id', '=', 'ip_addresses.subnet_id')
                ->whereColumn('ip_addresses.customer_id', '!=', 'subnets.customer_id')
                ->count();

            if ($mismatch > 0) {
                throw new RuntimeException('EPIC-001 Step 6: ip_addresses.customer_id does not match subnets.customer_id for some rows. Fix before migrating.');
            }
        }

        $missing = DB::table('ip_addresses')->whereNull('customer_id')->count();
        if ($missing > 0) {
            throw new RuntimeException("EPIC-001 Step 6: ip_addresses.customer_id contains {$missing} NULL rows. Fix data before migrating.");
        }

        $this->forceNotNull('ip_addresses', 'customer_id');
        $this->rebindCustomerForeignKey('ip_addresses');

        // Helpful index for scoped lookups (non-unique).
        try {
            Schema::table('ip_addresses', function (Blueprint $table) {
                $table->index(['customer_id', 'ip_addr'], 'ip_addresses_customer_ip_addr_index');
            });
        } catch (Throwable $_) {
            // ignore
        }
    }

    private function hardenRacks(): void
    {
        if (! Schema::hasTable('racks') || ! Schema::hasColumn('racks', 'customer_id')) {
            return;
        }

        if (Schema::hasColumn('racks', 'location_id') && Schema::hasTable('locations')) {
            DB::table('racks')
                ->whereNull('racks.customer_id')
                ->whereNotNull('racks.location_id')
                ->join('locations', 'locations.id', '=', 'racks.location_id')
                ->update(['racks.customer_id' => DB::raw('locations.customer_id')]);
        }

        $missing = DB::table('racks')->whereNull('customer_id')->count();
        if ($missing > 0) {
            throw new RuntimeException("EPIC-001 Step 6: racks.customer_id contains {$missing} NULL rows. Fix data before migrating.");
        }

        $this->forceNotNull('racks', 'customer_id');
        $this->rebindCustomerForeignKey('racks');
    }

    private function hardenDevices(): void
    {
        if (! Schema::hasTable('devices') || ! Schema::hasColumn('devices', 'customer_id')) {
            return;
        }

        // Prefer deriving from rack.
        if (Schema::hasColumn('devices', 'rack_id') && Schema::hasTable('racks') && Schema::hasColumn('racks', 'customer_id')) {
            DB::table('devices')
                ->whereNull('devices.customer_id')
                ->whereNotNull('devices.rack_id')
                ->join('racks', 'racks.id', '=', 'devices.rack_id')
                ->whereNotNull('racks.customer_id')
                ->update(['devices.customer_id' => DB::raw('racks.customer_id')]);
        }

        // Fallback to device.location_id.
        if (Schema::hasColumn('devices', 'location_id') && Schema::hasTable('locations')) {
            DB::table('devices')
                ->whereNull('devices.customer_id')
                ->whereNotNull('devices.location_id')
                ->join('locations', 'locations.id', '=', 'devices.location_id')
                ->update(['devices.customer_id' => DB::raw('locations.customer_id')]);
        }

        $missing = DB::table('devices')->whereNull('customer_id')->count();
        if ($missing > 0) {
            throw new RuntimeException("EPIC-001 Step 6: devices.customer_id contains {$missing} NULL rows. Assign devices to customers before migrating.");
        }

        $this->forceNotNull('devices', 'customer_id');
        $this->rebindCustomerForeignKey('devices');
    }

    private function rebindCustomerForeignKey(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable('customers') || ! Schema::hasColumn($table, 'customer_id')) {
            return;
        }

        // Drop and recreate FK so ON DELETE behavior matches NOT NULL (restrict).
        try {
            Schema::table($table, function (Blueprint $t) {
                try {
                    $t->dropForeign(['customer_id']);
                } catch (Throwable $_) {
                    // ignore
                }
            });
        } catch (Throwable $_) {
            // ignore
        }

        try {
            Schema::table($table, function (Blueprint $t) {
                $t->foreign('customer_id')
                    ->references('id')
                    ->on('customers')
                    ->onDelete('restrict');
            });
        } catch (Throwable $_) {
            // ignore if it already exists
        }
    }

    private function forceNotNull(string $table, string $column): void
    {
        // Cross-DB best-effort: MySQL + PostgreSQL.
        try {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` BIGINT UNSIGNED NOT NULL");
            return;
        } catch (Throwable $_) {
            // ignore
        }

        try {
            DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" SET NOT NULL");
        } catch (Throwable $_) {
            // ignore
        }
    }
};
