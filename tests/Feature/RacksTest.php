<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RacksTest extends TestCase
{
    use RefreshDatabase;

    public function test_rack_layout_preserves_supported_devices_and_inventory_and_rejects_collisions_and_stale_saves(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $model = $vendor->models()->create(['name' => 'Switch', 'rack_height_u' => 1]);
        $desktop = $vendor->models()->create(['name' => 'NAS']);
        $switch = $site->devices()->create(['name' => 'SW-01', 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id]);
        $nas = $site->devices()->create(['name' => 'NAS-01', 'manufacturer_id' => $vendor->id, 'device_model_id' => $desktop->id]);
        $interface = $nas->interfaces()->create(['name' => 'eth0', 'kind' => 'physical']);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.1'), 'device_id' => $nas->id, 'device_interface_id' => $interface->id]);
        $settings = ['name' => 'Armadio A', 'height_u' => 24, 'numbering' => 'bottom-up'];
        $this->post(route('companies.sites.racks.store', [$company, $site]), ['name' => $settings['name']])->assertSessionHasNoErrors();
        $rack = $site->racks()->sole();
        $this->assertSame(24, $rack->height_u);
        $this->assertSame('bottom-up', $rack->numbering);
        $url = route('companies.sites.racks.update', [$company, $site, $rack]);
        $items = [
            $this->item('a', 'device', 10, ['device_id' => $switch->id]),
            $this->item('b', 'shelf', 0),
            $this->item('c', 'device', 0, ['device_id' => $nas->id, 'shelf_key' => str_repeat('b', 32), 'height' => 4, 'width' => 40, 'x' => 30]),
            $this->item('d', 'drawer', 6, ['height' => 2]),
        ];
        $this->put($url, [...$settings, 'revision' => 0, 'items' => $items])->assertSessionHasNoErrors();
        $this->get(route('companies.sites.racks.show', [$company, $site, $rack]))->assertInertia(fn (Assert $page) => $page
            ->component('racks/show')->has('rack.items', 4)->where('rack.items.2.shelf_key', str_repeat('b', 32))
            ->where('rack.items.2.height', 4)->where('rack.items.2.width', 40));
        $settings['numbering'] = 'top-down';
        $this->put($url, [...$settings, 'revision' => 1])->assertSessionHasNoErrors();
        $this->assertSame([10, 0, 0, 6], $rack->items()->orderBy('id')->pluck('position')->all());
        $this->assertSame('top-down', $rack->fresh()->numbering);
        $this->put($url, [...$settings, 'revision' => 1, 'items' => []])->assertSessionHasErrors('revision');
        $this->put($url, [...$settings, 'revision' => 2, 'height_u' => 8])->assertSessionHasErrors('items');
        $overlap = $items;
        $overlap[0]['position'] = 3;
        $this->put($url, [...$settings, 'revision' => 2, 'items' => $overlap])->assertSessionHasErrors('items');
        $this->put($url, [...$settings, 'revision' => 2, 'items' => [$items[0], $items[2], $items[3]], 'removed_keys' => [$items[1]['key']]])->assertSessionHasErrors('items');
        $this->put($url, [...$settings, 'revision' => 2, 'items' => [$items[0], $items[3]]])->assertSessionHasErrors('items');
        $this->assertSame(2, $rack->fresh()->revision);
        $this->assertSame(4, $rack->items()->count());

        $this->patch(route('manufacturers.models.update', [$vendor, $model]), ['name' => 'Switch', 'rack_height_u' => 2])->assertSessionHasErrors('rack_height_u');
        $this->patch(route('companies.sites.devices.update', [$company, $site, $nas]), ['name' => 'NAS-01', 'device_model_id' => $model->id])->assertSessionHasErrors('device_model_id');
        $this->assertSame(1, $model->fresh()->rack_height_u);
        $this->put($url, [...$settings, 'revision' => 2, 'items' => [$items[0], $items[3]], 'removed_keys' => [$items[1]['key'], $items[2]['key']]])->assertSessionHasNoErrors();
        $this->assertModelExists($nas);
        $this->assertModelExists($interface);
        $this->assertModelExists($ip);
        $this->assertNull($desktop->fresh()->rack_height_u);

        $this->delete(route('companies.sites.racks.destroy', [$company, $site, $rack]), ['confirmed' => true, 'revision' => 2])->assertSessionHasErrors('revision');
        $this->delete(route('companies.sites.racks.destroy', [$company, $site, $rack]), ['confirmed' => true, 'revision' => 3])->assertSessionHasNoErrors();
        $this->assertModelMissing($rack);
        $this->assertModelExists($switch);
        $this->assertModelExists($ip);
    }

    public function test_rack_routes_and_device_assignments_stay_in_the_authorized_site_and_one_rack(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $otherSite = $company->sites()->create(['name' => 'Milano']);
        $beta = Company::create(['name' => 'Beta']);
        $betaSite = $beta->sites()->create(['name' => null]);
        $user = User::factory()->create();
        $company->users()->attach($user);
        $this->actingAs($user);
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $model = $vendor->models()->create(['name' => 'UPS', 'rack_height_u' => 2]);
        $device = $site->devices()->create(['name' => 'UPS-01', 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id]);
        $foreign = $otherSite->devices()->create(['name' => 'UPS-02', 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id]);
        $settings = ['name' => 'Armadio', 'height_u' => 24, 'numbering' => 'bottom-up'];
        $rack = $site->racks()->create($settings);
        $otherRack = $site->racks()->create([...$settings, 'name' => 'Secondo']);
        $url = route('companies.sites.racks.update', [$company, $site, $rack]);
        $item = $this->item('a', 'device', 0, ['device_id' => $device->id, 'height' => 2]);
        $this->get(route('companies.sites.racks.index', [$beta, $betaSite]))->assertForbidden();
        $this->get(route('companies.sites.racks.show', [$company, $otherSite, $rack]))->assertNotFound();
        $this->post(route('companies.sites.racks.store', [$company, $betaSite]), $settings)->assertNotFound();
        $this->put($url, [...$settings, 'revision' => 0, 'items' => [[...$item, 'device_id' => $foreign->id]]])->assertSessionHasErrors('items');
        $this->put($url, [...$settings, 'revision' => 0, 'items' => [[...$item, 'height' => 1]]])->assertSessionHasErrors('items');
        $this->put($url, [...$settings, 'revision' => 0, 'items' => [$item]])->assertSessionHasNoErrors();
        $this->put(route('companies.sites.racks.update', [$company, $site, $otherRack]), [...$settings, 'revision' => 0, 'items' => [$item]])->assertSessionHasErrors('items');
        $this->assertSame(0, $otherRack->items()->count());
        $this->assertSame($rack->id, $device->rackItem()->sole()->rack_id);
        $this->put($url, ['name' => 'Armadio rinominato', 'revision' => 1])->assertSessionHasNoErrors();
        $this->assertSame(24, $rack->fresh()->height_u);
        $this->assertSame('bottom-up', $rack->fresh()->numbering);
        $this->assertSame($rack->id, $device->rackItem()->sole()->rack_id);

        $impact = $this->getJson(route('companies.sites.devices.deletion-impact', [$company, $site, $device]))
            ->assertOk()->assertJsonPath('rack_item.rack.name', 'Armadio rinominato')->json();
        $this->delete(route('companies.sites.devices.destroy', [$company, $site, $device]), ['confirmed' => true, 'impact_token' => $impact['impact_token']])->assertSessionHasNoErrors();
        $this->assertSame(0, $rack->items()->count());
        $this->assertSame(3, $rack->fresh()->revision);
        $this->assertModelExists($foreign);
    }

    public function test_model_without_rack_ears_keeps_its_height_but_uses_adjustable_shelf_placement(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $this->post(route('manufacturers.models.store', $vendor), [
            'name' => 'NAS', 'rack_height_u' => 4, 'without_rack_ears' => true,
        ])->assertSessionHasNoErrors();
        $model = $vendor->models()->sole();
        $this->assertTrue($model->without_rack_ears);
        $this->assertSame(4, $model->rack_height_u);
        $nas = $site->devices()->create(['name' => 'NAS-01', 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id]);
        $rack = $site->racks()->create(['name' => 'Armadio', 'height_u' => 24, 'numbering' => 'bottom-up']);
        $url = route('companies.sites.racks.update', [$company, $site, $rack]);
        $shelf = $this->item('a', 'shelf', 0);
        $device = $this->item('b', 'device', 0, ['device_id' => $nas->id, 'height' => 4]);
        $this->put($url, ['name' => $rack->name, 'revision' => 0, 'items' => [$device]])->assertSessionHasErrors('items');
        $device = [...$device, 'shelf_key' => $shelf['key'], 'width' => 40, 'x' => 12.5];
        $this->put($url, ['name' => $rack->name, 'revision' => 0, 'items' => [$shelf, $device]])->assertSessionHasNoErrors();
        $device = [...$device, 'height' => 3.5, 'width' => 35, 'x' => 50];
        $this->put($url, ['name' => $rack->name, 'revision' => 1, 'items' => [$shelf, $device]])->assertSessionHasNoErrors();
        $placement = $nas->rackItem()->sole();
        $this->assertSame($shelf['key'], $placement->shelf_key);
        $this->assertSame(3.5, $placement->height);
        $this->assertSame(35.0, $placement->width);
        $this->assertSame(50.0, $placement->x);
        $this->assertSame(4, $model->fresh()->rack_height_u);

        $modelUrl = route('manufacturers.models.update', [$vendor, $model]);
        $this->patch($modelUrl, ['name' => 'NAS', 'without_rack_ears' => false])->assertSessionHasErrors('without_rack_ears');
        $this->assertTrue($model->fresh()->without_rack_ears);
        $this->put($url, ['name' => $rack->name, 'revision' => 2, 'items' => [$shelf], 'removed_keys' => [$device['key']]])->assertSessionHasNoErrors();
        $this->patch($modelUrl, ['name' => 'NAS', 'without_rack_ears' => false])->assertSessionHasNoErrors();
        $this->assertFalse($model->fresh()->without_rack_ears);
        $this->put($url, ['name' => $rack->name, 'revision' => 3, 'items' => [$shelf, $device]])->assertSessionHasErrors('items');
    }

    public function test_devices_can_support_a_stack_without_cycles_or_removing_its_base(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $rackModel = $vendor->models()->create(['name' => 'UPS', 'rack_height_u' => 2]);
        $desktop = $vendor->models()->create(['name' => 'NAS', 'rack_height_u' => 4, 'without_rack_ears' => true]);
        $base = $site->devices()->create(['name' => 'UPS', 'manufacturer_id' => $vendor->id, 'device_model_id' => $rackModel->id]);
        $nas = $site->devices()->create(['name' => 'NAS', 'manufacturer_id' => $vendor->id, 'device_model_id' => $desktop->id]);
        $top = $site->devices()->create(['name' => 'PBX', 'manufacturer_id' => $vendor->id, 'device_model_id' => $desktop->id]);
        $rack = $site->racks()->create(['name' => 'Armadio', 'height_u' => 12, 'numbering' => 'bottom-up']);
        $url = route('companies.sites.racks.update', [$company, $site, $rack]);
        $items = [
            $this->item('a', 'device', 2, ['device_id' => $base->id, 'height' => 2]),
            $this->item('b', 'device', 2, ['device_id' => $nas->id, 'shelf_key' => str_repeat('a', 32), 'height' => 4, 'width' => 40, 'x' => 20]),
            $this->item('c', 'device', 2, ['device_id' => $top->id, 'shelf_key' => str_repeat('b', 32), 'height' => 1, 'width' => 20, 'x' => 25]),
        ];
        $this->put($url, ['name' => $rack->name, 'revision' => 0, 'items' => array_reverse($items)])->assertSessionHasNoErrors();
        $this->assertSame($items[1]['key'], $top->rackItem()->sole()->shelf_key);
        $this->put($url, ['name' => $rack->name, 'revision' => 1, 'height_u' => 8])->assertSessionHasErrors('items');
        $cycle = $items;
        $cycle[1]['shelf_key'] = $items[2]['key'];
        $this->put($url, ['name' => $rack->name, 'revision' => 1, 'items' => $cycle])->assertSessionHasErrors('items');
        $unsupported = $items;
        $unsupported[2]['x'] = 80;
        $this->put($url, ['name' => $rack->name, 'revision' => 1, 'items' => $unsupported])->assertSessionHasErrors('items');
        $this->put($url, ['name' => $rack->name, 'revision' => 1, 'items' => array_slice($items, 1), 'removed_keys' => [$items[0]['key']]])->assertSessionHasErrors('items');
        $impact = $this->getJson(route('companies.sites.devices.deletion-impact', [$company, $site, $base]))->assertOk()->json();
        $this->delete(route('companies.sites.devices.destroy', [$company, $site, $base]), ['confirmed' => true, 'impact_token' => $impact['impact_token']])->assertSessionHasErrors('confirmed');
        $this->assertModelExists($base);
        $this->assertSame(3, $rack->items()->count());
        $this->assertSame(1, $rack->fresh()->revision);
        $raised = array_map(fn ($item) => [...$item, 'position' => 5], $items);
        $this->put($url, ['name' => $rack->name, 'revision' => 1, 'items' => $raised])->assertSessionHasNoErrors();
        $this->assertSame([5, 5, 5], $rack->items()->orderBy('id')->pluck('position')->all());
    }

    public function test_shelf_base_does_not_add_a_unit_to_the_device_height(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $model = $vendor->models()->create(['name' => 'NAS', 'rack_height_u' => 4, 'without_rack_ears' => true]);
        $nas = $site->devices()->create(['name' => 'NAS', 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id]);
        $rack = $site->racks()->create(['name' => 'Armadio', 'height_u' => 4, 'numbering' => 'bottom-up']);
        $url = route('companies.sites.racks.update', [$company, $site, $rack]);
        $shelf = $this->item('a', 'shelf', 0);
        $device = $this->item('b', 'device', 0, ['device_id' => $nas->id, 'shelf_key' => $shelf['key'], 'height' => 4, 'width' => 40, 'x' => 20]);
        $this->put($url, ['name' => $rack->name, 'revision' => 0, 'items' => [$device, $shelf]])->assertSessionHasNoErrors();
        $this->assertSame(4.0, $nas->rackItem()->sole()->height);
        $this->put($url, ['name' => $rack->name, 'revision' => 1, 'height_u' => 3])->assertSessionHasErrors('items');
        $this->put($url, ['name' => $rack->name, 'revision' => 1, 'items' => [$device, $shelf, $this->item('c', 'shelf', 0)]])->assertSessionHasErrors('items');
        $this->put($url, ['name' => $rack->name, 'revision' => 1, 'items' => [$device, $shelf, $this->item('c', 'shelf', 4)]])->assertSessionHasErrors('items');
        $this->assertSame(1, $rack->fresh()->revision);
    }

    private function item(string $key, string $kind, int $position, array $attributes = []): array
    {
        return [...['key' => str_repeat($key, 32), 'kind' => $kind, 'position' => $position, 'device_id' => null, 'shelf_key' => null, 'height' => 1, 'width' => 100, 'x' => 0], ...$attributes];
    }
}
