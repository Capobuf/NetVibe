<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use App\Models\Subnet;

class PurgeEmptyIpsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_empty_ips_deletes_only_placeholder_rows()
    {
        $ipService = app(\App\Services\IpConversionService::class);

        $customer = \App\Models\Customer::factory()->create();

        $subnet = Subnet::create([
            'customer_id' => $customer->id,
            'subnet' => $ipService->toDecimal('192.168.77.0'),
            'mask' => 29,
        ]);

        // Insert placeholder row: no hostname/description/device_id, type host, state 0
        $placeholderIp = $ipService->toDecimal('192.168.77.2');
        $id1 = DB::table('ip_addresses')->insertGetId([
            'subnet_id' => $subnet->id,
            'ip_addr' => $placeholderIp,
            'ip_str' => '192.168.77.2',
            'type' => 'host',
            'state' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert a real used IP — should not be deleted
        $usedIp = $ipService->toDecimal('192.168.77.3');
        $id2 = DB::table('ip_addresses')->insertGetId([
            'subnet_id' => $subnet->id,
            'ip_addr' => $usedIp,
            'ip_str' => '192.168.77.3',
            'type' => 'host',
            'state' => 2,
            'hostname' => 'server.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert a reserved IP — should not be deleted
        $reservedIp = $ipService->toDecimal('192.168.77.4');
        $id3 = DB::table('ip_addresses')->insertGetId([
            'subnet_id' => $subnet->id,
            'ip_addr' => $reservedIp,
            'ip_str' => '192.168.77.4',
            'type' => 'reserved',
            'state' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('ip_addresses', ['id' => $id1]);
        $this->assertDatabaseHas('ip_addresses', ['id' => $id2]);
        $this->assertDatabaseHas('ip_addresses', ['id' => $id3]);

        // Run the purge migration up() directly
        $migration = include database_path('migrations/2025_03_01_000001_purge_empty_ips.php');
        $migration->up();

        // Placeholder should be deleted
        $this->assertDatabaseMissing('ip_addresses', ['id' => $id1]);

        // Real/Reserved should remain
        $this->assertDatabaseHas('ip_addresses', ['id' => $id2]);
        $this->assertDatabaseHas('ip_addresses', ['id' => $id3]);
    }
}
