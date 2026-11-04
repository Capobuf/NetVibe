<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeviceSourcesLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_sources_load_only_on_request_and_keep_device_and_user_scope(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $manufacturer = Manufacturer::create(['name' => 'MikroTik']);
        $device = $site->devices()->create(['name' => 'Gateway', 'manufacturer_id' => $manufacturer->id]);
        $other = $site->devices()->create(['name' => 'Other gateway', 'manufacturer_id' => $manufacturer->id]);
        foreach ([$device, $other] as $router) {
            $source = $router->mikrotikSources()->make([
                'generation' => '00000000-0000-4000-8000-000000000001',
                'kind' => 'observation', 'source_key' => 'arp-1', 'state' => 'current',
                'data' => ['menu' => '/ip/arp', 'mac' => '02:00:00:00:00:01'],
                'anchor' => [], 'seen_at' => now(), 'address' => ip2long('192.0.2.10'),
            ]);
            $source->site_id = $site->id;
            $source->save();
        }
        $url = route('companies.sites.devices.show', [$company, $site, $device]);
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('devices/show')->missing('mikrotikSources')
            ->reloadOnly('mikrotikSources', fn (Assert $reload) => $reload
                ->has('mikrotikSources', 1)->where('mikrotikSources.0.device_id', $device->id)
                ->where('mikrotikSources.0.ip', '192.0.2.10')));

        $device->mikrotikSources()->sole()->update(['address' => ip2long('192.0.2.11')]);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'devices/show', 'X-Inertia-Partial-Data' => 'mikrotikSources'];
        $this->get($url, $headers)->assertOk()->assertJsonPath('props.mikrotikSources.0.ip', '192.0.2.11');

        $member = User::factory()->create();
        $company->users()->attach($member);
        $this->actingAs($member)->get($url, $headers)->assertOk()->assertJsonPath('props.mikrotikSources', []);
        $this->actingAs(User::factory()->create())->get($url, $headers)->assertForbidden();
    }
}
