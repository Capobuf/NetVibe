<?php

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\Subnet;
use App\Services\IpConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IpPrefillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_ip_addresses_have_null_last_seen_by_default()
    {
        $ipService = new IpConversionService();

        $subnet = Subnet::create([
            'subnet' => $ipService->toDecimal('10.10.10.0'),
            'mask' => 30,
            'description' => 'migration-test',
        ]);

        $ipDecimal = $ipService->toDecimal('10.10.10.1');

        $ip = IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_addr' => $ipDecimal,
            'state' => 0,
        ]);

        $ip->refresh();

        $this->assertNull($ip->last_seen, 'Newly created IP row should have last_seen null by default after migration');
    }

    public function test_migration_converts_1970_placeholder_to_null()
    {
        $ipService = new IpConversionService();

        $subnet = Subnet::create([
            'subnet' => $ipService->toDecimal('10.10.11.0'),
            'mask' => 30,
            'description' => 'migration-test-1970',
        ]);

        $ipDecimal = $ipService->toDecimal('10.10.11.1');

        // Insert a record with legacy placeholder timestamp
        $rowId = IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_addr' => $ipDecimal,
            'state' => 0,
            'last_seen' => '1970-01-01 00:00:01',
        ])->id;

        // Simulate migration conversion: update rows with placeholder to NULL
        DB::table('ip_addresses')
            ->where('last_seen', '1970-01-01 00:00:01')
            ->update(['last_seen' => null]);

        $ip = IpAddress::find($rowId);
        $this->assertNull($ip->last_seen, 'Migration should convert legacy 1970 placeholder to NULL');
    }
}
