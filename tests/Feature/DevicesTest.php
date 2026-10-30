<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\ModelType;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DevicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_users_can_document_devices_but_cannot_cross_sites_or_edit_the_global_catalog(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $otherSite = $company->sites()->create(['name' => 'Milano']);
        $beta = Company::create(['name' => 'Beta']);
        $betaSite = $beta->sites()->create(['name' => null]);
        $manufacturer = Manufacturer::create(['name' => 'Vendor']);
        $model = $manufacturer->models()->create(['name' => 'Switch']);
        $user = User::factory()->create();
        $company->users()->attach($user);
        $foreign = $otherSite->devices()->create(['name' => 'Altro', 'manufacturer_id' => $manufacturer->id]);
        $foreignInterface = $foreign->interfaces()->create(['name' => 'eth0']);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $this->actingAs($user);

        $storeDevice = route('companies.sites.devices.store', [$company, $site]);
        $this->post($storeDevice, ['name' => 'Server'])->assertSessionHasErrors('device_model_id');
        $this->post($storeDevice, ['name' => 'Server', 'device_model_id' => $model->id, 'serial_number' => 'SN-001'])
            ->assertSessionHasNoErrors();
        $device = $site->devices()->sole();
        $this->assertSame($manufacturer->id, $device->manufacturer_id);
        $this->assertSame('SN-001', $device->serial_number);
        $this->patch(route('companies.sites.devices.update', [$company, $site, $device]), ['name' => 'Server', 'device_model_id' => $model->id, 'manufacturer_id' => 0, 'serial_number' => 'SN-002'])
            ->assertSessionHasNoErrors();
        $this->assertSame($manufacturer->id, $device->fresh()->manufacturer_id);
        $this->assertSame('SN-002', $device->fresh()->serial_number);
        $this->get(route('companies.sites.devices.show', [$company, $site, $device]))->assertOk();
        $this->get(route('companies.sites.devices.show', [$company, $site, $foreign]))->assertNotFound();
        $this->get(route('companies.sites.devices.index', [$beta, $betaSite]))->assertForbidden();
        $this->post(route('manufacturers.store'), ['name' => 'Unauthorized'])->assertForbidden();
        $this->get(route('manufacturers.index'))->assertForbidden();
        $this->get(route('device-models.index'))->assertForbidden();
        $this->postJson(route('manufacturers.store'), ['name' => 'Unauthorized'])->assertForbidden();
        $this->post(route('model-types.store'), ['name' => 'Unauthorized'])->assertForbidden();
        $store = route('companies.sites.subnets.ip-addresses.store', [$company, $site, $subnet]);
        $this->post($store, ['ip' => '10.0.0.1', 'device_id' => $foreign->id])->assertSessionHasErrors('device_id');
        $this->post($store, ['ip' => '10.0.0.1', 'device_id' => $device->id, 'device_interface_id' => $foreignInterface->id])
            ->assertSessionHasErrors('device_interface_id');
        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_multiple_ips_and_independent_macs_survive_interface_and_device_deletion(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $manufacturer = Manufacturer::create(['name' => 'Vendor']);
        $device = $site->devices()->create(['name' => 'Server', 'manufacturer_id' => $manufacturer->id]);
        $interface = $device->interfaces()->create(['name' => 'Management', 'kind' => 'logical', 'mac_address' => 'AA:BB:CC:DD:EE:01']);
        $store = route('companies.sites.subnets.ip-addresses.store', [$company, $site, $subnet]);
        foreach (['10.0.0.1', '10.0.0.2'] as $address) {
            $this->post($store, ['ip' => $address, 'device_id' => $device->id, 'device_interface_id' => $interface->id, 'mac_address' => 'AA:BB:CC:DD:EE:02'])
                ->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('ip_addresses', 2);
        $this->assertSame('AA:BB:CC:DD:EE:01', $interface->fresh()->mac_address);
        $this->get(route('companies.sites.search.index', [$company, $site, 'search' => 'Server']))
            ->assertInertia(fn (Assert $page) => $page->where('results.total', 2));
        $this->delete(route('companies.sites.devices.interfaces.destroy', [$company, $site, $device, $interface]), ['confirmed' => true])
            ->assertSessionHasNoErrors();
        $ip = $subnet->ipAddresses()->orderBy('id')->first();
        $this->assertDatabaseHas('ip_addresses', ['id' => $ip->id, 'device_id' => null, 'device_interface_id' => null, 'mac_address' => 'AA:BB:CC:DD:EE:02']);
        $this->post(route('companies.sites.devices.ip-addresses.store', [$company, $site, $device]), ['ip_address_id' => $ip->id])
            ->assertSessionHasNoErrors();
        $impact = $this->getJson(route('companies.sites.devices.deletion-impact', [$company, $site, $device]))->assertOk()->json();
        $this->delete(route('companies.sites.devices.destroy', [$company, $site, $device]), ['confirmed' => true, 'impact_token' => $impact['impact_token']])
            ->assertSessionHasNoErrors();
        $this->assertModelMissing($device);
        $this->assertDatabaseCount('ip_addresses', 2);
        $this->assertDatabaseHas('ip_addresses', ['id' => $ip->id, 'device_id' => null, 'mac_address' => 'AA:BB:CC:DD:EE:02']);
    }

    public function test_persisted_panel_images_and_stable_interfaces_survive_geometry_changes_and_reject_structural_loss(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $manufacturer = Manufacturer::create(['name' => 'Vendor']);
        $model = $manufacturer->models()->create(['name' => 'Switch']);
        $panel = $this->panel();
        $url = route('manufacturers.models.panel.update', [$manufacturer, $model]);
        $payload = fn (array $document, int $revision) => ['document' => json_encode($document), 'revision' => $revision,
            'images' => ['front' => UploadedFile::fake()->image('front.png', 100, 50), 'rear' => UploadedFile::fake()->image('rear.png', 100, 50)]];
        $openEditorPanel = $panel;
        $openEditorPanel['faces']['front']['ports'][0] += ['count' => 8, 'columns' => 4, 'gapX' => 16, 'gapY' => 32, 'columnFirst' => false];
        $this->post($url, $payload($openEditorPanel, 0), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('revision', 1);
        $model->refresh();
        $this->assertEquals($panel, $model->panel);
        $invalidPanel = $panel;
        $invalidPanel['faces']['front']['ports'][0]['unexpected'] = true;
        $this->post($url, $payload($invalidPanel, 1), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('panel.faces.front.ports.0');
        foreach ($model->assets as $id => $path) {
            Storage::disk('local')->assertExists($path);
            $this->get(route('device-models.assets', [$model, $id]))->assertOk();
        }
        $this->get(route('manufacturers.models.edit', [$manufacturer, $model]))
            ->assertInertia(fn (Assert $page) => $page->where('catalogModel.panel', $panel));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $this->post(route('companies.sites.devices.store', [$company, $site]), ['name' => 'SW-01', 'manufacturer_id' => $manufacturer->id, 'device_model_id' => $model->id])->assertSessionHasNoErrors();
        $device = $site->devices()->sole();
        $interface = $device->interfaces()->sole();
        $interface->update(['name' => 'Uplink', 'mac_address' => 'AA:BB:CC:DD:EE:01']);
        $panel['faces']['front']['ports'][0]['x'] = 120;
        $this->post($url, $payload($panel, 1), ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($interface->id, $device->interfaces()->sole()->id);
        $this->assertSame('Uplink', $interface->fresh()->name);
        $this->assertSame('AA:BB:CC:DD:EE:01', $interface->fresh()->mac_address);
        $this->assertSame(120, $model->fresh()->panel['faces']['front']['ports'][0]['x']);
        $oldAssets = $model->fresh()->assets;
        $panel['faces']['front']['ports'] = [];
        $this->post($url, $payload($panel, 2), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('document');
        foreach ($oldAssets as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $this->delete(route('manufacturers.models.destroy', [$manufacturer, $model]), ['confirmed' => true])->assertSessionHasErrors('confirmed');
        $this->assertModelExists($device);
        $this->assertModelExists($model);
    }

    public function test_container_impact_includes_devices_and_rejects_stale_confirmation(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $manufacturer = Manufacturer::create(['name' => 'Vendor']);
        $device = $site->devices()->create(['name' => 'Server', 'manufacturer_id' => $manufacturer->id]);
        $other = Company::create(['name' => 'Beta']);
        $otherSite = $other->sites()->create(['name' => null]);
        $otherDevice = $otherSite->devices()->create(['name' => 'Server Beta', 'manufacturer_id' => $manufacturer->id]);
        $preview = route('administration.companies.deletion-impact', $company);
        $delete = route('administration.companies.destroy', $company);
        $impact = $this->getJson($preview)->assertOk()->assertJsonCount(1, 'devices')->json();
        $device->interfaces()->create(['name' => 'eth0']);
        $this->delete($delete, ['confirmed' => true, 'impact_token' => $impact['impact_token']])->assertSessionHasErrors('impact_token');
        $impact = $this->getJson($preview)->assertJsonCount(1, 'interfaces')->json();
        $this->delete($delete, ['confirmed' => true, 'impact_token' => $impact['impact_token']])->assertSessionHasNoErrors();
        $this->assertModelMissing($device);
        $this->assertModelExists($otherDevice);
        $this->assertModelExists($manufacturer);
    }

    public function test_quick_manufacturers_and_configurable_types_are_persisted_on_models(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $response = $this->postJson(route('manufacturers.store'), ['name' => 'Vendor'])
            ->assertCreated()->assertJsonPath('name', 'Vendor');
        $manufacturer = Manufacturer::findOrFail($response->json('id'));
        $this->postJson(route('manufacturers.store'), ['name' => 'Vendor'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(route('model-types.store'), ['name' => 'Switch'])->assertCreated()->assertJsonPath('name', 'Switch');
        $this->postJson(route('model-types.store'), ['name' => 'Switch'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $type = ModelType::sole();
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $context = route('companies.sites.devices.index', [$company, $site]);
        $this->from($context)->post(route('manufacturers.models.store', $manufacturer), ['name' => 'Core', 'model_type_id' => $type->id, 'inline' => true])
            ->assertRedirect($context)->assertSessionHasNoErrors()
            ->assertInertiaFlash('createdModel.manufacturer_id', $manufacturer->id)
            ->assertInertiaFlash('createdModel.name', 'Core');
        $model = $manufacturer->models()->sole();
        $this->assertSame($type->id, $model->model_type_id);
        $this->delete(route('model-types.destroy', $type), ['confirmed' => true])->assertSessionHasErrors('confirmed');
        $this->patch(route('model-types.update', $type), ['name' => 'Access switch'])->assertSessionHasNoErrors();
        $this->post(route('companies.sites.devices.store', [$company, $site]), ['name' => 'SW-01', 'manufacturer_id' => $manufacturer->id, 'device_model_id' => $model->id])
            ->assertSessionHasNoErrors();
        $device = $site->devices()->sole();
        $this->get(route('companies.sites.devices.show', [$company, $site, $device]))
            ->assertInertia(fn (Assert $page) => $page->missing('device.type')->where('device.model.model_type.name', 'Access switch'));
        $this->patch(route('manufacturers.models.update', [$manufacturer, $model]), ['name' => 'Core', 'model_type_id' => null])->assertSessionHasNoErrors();
        $this->delete(route('model-types.destroy', $type), ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertModelMissing($type);
        $this->assertModelExists($device);
    }

    public function test_changing_a_model_manufacturer_updates_its_devices_and_rejects_duplicate_models(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $manufacturer = Manufacturer::create(['name' => 'Original vendor']);
        $target = Manufacturer::create(['name' => 'New vendor']);
        $model = $manufacturer->models()->create(['name' => 'Core', 'panel' => $this->panel()]);
        $originalPanel = $model->fresh()->panel;
        $target->models()->create(['name' => 'Existing']);
        $devices = collect(['Alfa', 'Beta'])->map(function ($name) use ($manufacturer, $model) {
            $company = Company::create(['name' => $name]);
            $site = $company->sites()->create(['name' => null]);

            return $site->devices()->create(['name' => 'SW-01', 'manufacturer_id' => $manufacturer->id, 'device_model_id' => $model->id]);
        });
        $url = route('manufacturers.models.update', [$manufacturer, $model]);

        $this->patch($url, ['name' => 'Core', 'manufacturer_id' => 0])->assertSessionHasErrors('manufacturer_id');
        $this->patch($url, ['name' => 'Existing', 'manufacturer_id' => $target->id])->assertSessionHasErrors('name');
        $this->assertDatabaseHas('device_models', ['id' => $model->id, 'name' => 'Core', 'manufacturer_id' => $manufacturer->id]);

        $this->patch($url, ['name' => 'Core', 'manufacturer_id' => $target->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('device_models', ['id' => $model->id, 'manufacturer_id' => $target->id]);
        $this->assertSame($originalPanel, $model->fresh()->panel);
        foreach ($devices as $device) {
            $this->assertDatabaseHas('devices', ['id' => $device->id, 'device_model_id' => $model->id, 'manufacturer_id' => $target->id]);
        }
        $this->get(route('manufacturers.models.edit', [$target, $model]))->assertOk();
    }

    public function test_interface_ip_choices_include_unassigned_device_ips_and_stay_within_the_site(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $otherSite = $company->sites()->create(['name' => 'Milano']);
        $manufacturer = Manufacturer::create(['name' => 'Vendor']);
        $device = $site->devices()->create(['name' => 'Switch', 'manufacturer_id' => $manufacturer->id]);
        $interface = $device->interfaces()->create(['name' => 'Management', 'kind' => 'logical']);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $subnet->excludedRanges()->create(['start_address' => ip2long('10.0.0.100'), 'end_address' => ip2long('10.0.0.110')]);
        $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.1'), 'description' => 'Available']);
        $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.2'), 'device_id' => $device->id]);
        $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.3'), 'device_id' => $device->id, 'device_interface_id' => $interface->id]);
        $otherSubnet = $otherSite->subnets()->create(Ipv4Cidr::parse('10.1.0.0/24'));
        $otherSubnet->ipAddresses()->create(['address' => ip2long('10.1.0.1'), 'description' => 'Other site']);

        $this->get(route('companies.sites.devices.show', [$company, $site, $device]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('availableIps', 2)->where('availableIps.0.ip', '10.0.0.1')->where('availableIps.1.ip', '10.0.0.2')
            ->has('subnets', 1)->where('subnets.0.id', $subnet->id)
            ->where('subnets.0.excludedRanges.0.start_ip', '10.0.0.100'));
    }

    private function panel(): array
    {
        $face = ['ports' => [], 'image' => null, 'order' => [], 'shellState' => ['hidden' => false, 'locked' => false]];
        $panel = ['version' => 3, 'name' => 'Switch', 'shell' => ['x' => 0, 'y' => 0, 'width' => 900, 'height' => 180, 'radius' => 8, 'border' => 2],
            'faces' => ['front' => $face, 'rear' => $face], 'numbering' => ['next' => 2, 'prefix' => '']];
        $panel['faces']['front']['ports'][] = ['id' => str_repeat('a', 32), 'type' => 'rj45', 'x' => 50, 'y' => 50, 'rotation' => 0, 'scale' => 1,
            'label' => '1', 'labelPosition' => 'center', 'poe' => true, 'group' => null, 'opacity' => 1, 'opacityCustomized' => false, 'borderColor' => '#3b82f6', 'hidden' => false, 'locked' => false];
        foreach (['front' => 'b', 'rear' => 'c'] as $side => $id) {
            $panel['faces'][$side]['image'] = ['assetId' => str_repeat($id, 32), 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 50, 'scale' => 1,
                'opacity' => 1, 'clip' => true, 'crop' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 50], 'hidden' => false, 'locked' => false];
        }

        return $panel;
    }
}
