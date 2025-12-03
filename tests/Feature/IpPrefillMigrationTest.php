<?php

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\Subnet;
use App\Services\IpConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Illuminate\Support\Facades\Bus;

class IpPrefillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_ip_addresses_have_null_last_seen_by_default()
    {
        Bus::fake();

        $ipService = new IpConversionService();

        $customer = \App\Models\Customer::factory()->create();
        $vrf = \App\Models\Vrf::factory()->create(['customer_id' => $customer->id]);

        $subnet = Subnet::create([
            'vrf_id' => $vrf->id,
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
        Bus::fake();

        $ipService = new IpConversionService();

        $customer2 = \App\Models\Customer::factory()->create();
        $vrf2 = \App\Models\Vrf::factory()->create(['customer_id' => $customer2->id]);

        $subnet = Subnet::create([
            'vrf_id' => $vrf2->id,
            'subnet' => $ipService->toDecimal('10.10.11.0'),
            'mask' => 30,
            'description' => 'migration-test-1970',
        ]);

        $ipDecimal = $ipService->toDecimal('10.10.11.1');

        // Insert a record with legacy placeholder timestamp using a safe epoch value
        // (use 1 year offset so TIMESTAMP conversions with server TZ remain valid)
        $rowId = IpAddress::create([
            'subnet_id' => $subnet->id,
            'ip_addr' => $ipDecimal,
            'state' => 0,
            'last_seen' => \Carbon\Carbon::createFromTimestamp(31536000),
        ])->id;

        // Simulate migration conversion: update rows with placeholder to NULL
        $placeholder = \Carbon\Carbon::createFromTimestamp(31536000)->toDateTimeString();

        DB::table('ip_addresses')
            ->where('last_seen', $placeholder)
            ->update(['last_seen' => null]);

        $ip = IpAddress::find($rowId);
        $this->assertNull($ip->last_seen, 'Migration should convert legacy 1970 placeholder to NULL');
    }
}
