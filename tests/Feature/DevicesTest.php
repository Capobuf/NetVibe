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

    public function test_infrastructure_classification_inherits_live_defaults_and_preserves_explicit_choices(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $type = ModelType::create(['name' => 'Switch', 'is_infrastructure' => true]);
        $model = $vendor->models()->create(['name' => 'Core', 'model_type_id' => $type->id]);
        $untyped = $vendor->models()->create(['name' => 'Generic']);
        $devices = collect([null, true, false])->map(fn ($override, $index) => $site->devices()->create([
            'name' => 'Device '.$index, 'manufacturer_id' => $vendor->id,
            'device_model_id' => $model->id, 'infrastructure_override' => $override,
        ]));
        $index = route('companies.sites.devices.index', [$company, $site]);
        $this->get($index)->assertInertia(fn (Assert $page) => $page
            ->where('view', 'infrastructure')->has('devices', 3)
            ->where('devices.0.is_infrastructure', true)->where('devices.1.is_infrastructure', true)
            ->where('devices.2.is_infrastructure', false));
        $this->patch(route('model-types.update', $type), ['name' => $type->name, 'is_infrastructure' => false])
            ->assertSessionHasNoErrors();
        $this->get($index.'?view=all')->assertInertia(fn (Assert $page) => $page
            ->where('view', 'all')->has('devices', 3)
            ->where('devices.0.is_infrastructure', false)->where('devices.1.is_infrastructure', true)
            ->where('devices.2.is_infrastructure', false));
        $this->assertSame([null, true, false], $site->devices()->orderBy('id')->pluck('infrastructure_override')->all());

        $device = $devices[0];
        $update = route('companies.sites.devices.update', [$company, $site, $device]);
        $this->patch($update, ['name' => $device->name, 'device_model_id' => $untyped->id, 'infrastructure_override' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($device->fresh()->isInfrastructure());
        $this->patch($update, ['name' => $device->name, 'device_model_id' => $model->id])->assertSessionHasNoErrors();
        $this->assertTrue($device->fresh()->infrastructure_override);
        $this->patch(route('companies.sites.devices.classification', [$company, $site, $device]), ['infrastructure_override' => null])
            ->assertSessionHasNoErrors();
        $this->assertFalse($device->fresh()->isInfrastructure());
        $type->refresh()->update(['is_infrastructure' => true]);
        $this->assertTrue($device->fresh()->isInfrastructure());
        $model->update(['model_type_id' => null]);
        $this->assertFalse($device->fresh()->isInfrastructure());
        $device->update(['device_model_id' => null]);
        $this->assertFalse($device->fresh()->isInfrastructure());
        $device->update(['infrastructure_override' => true]);
        $this->assertTrue($device->fresh()->isInfrastructure());
    }

    public function test_classification_updates_validate_input_and_respect_company_site_and_catalog_permissions(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $otherSite = $company->sites()->create(['name' => 'Milano']);
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $model = $vendor->models()->create(['name' => 'Switch']);
        $device = $site->devices()->create(['name' => 'Core', 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id]);
        $url = route('companies.sites.devices.classification', [$company, $site, $device]);
        $this->patch($url, ['infrastructure_override' => true])->assertRedirect(route('login'));
        $user = User::factory()->create();
        $this->actingAs($user)->patch($url, ['infrastructure_override' => true])->assertForbidden();
        $company->users()->attach($user);
        $this->patch(route('companies.sites.devices.classification', [$company, $otherSite, $device]), ['infrastructure_override' => true])->assertNotFound();
        $this->patch($url, [])->assertSessionHasErrors('infrastructure_override');
        $this->patch($url, ['infrastructure_override' => 'invalid'])->assertSessionHasErrors('infrastructure_override');
        $this->assertNull($device->fresh()->infrastructure_override);
        $this->patch($url, ['infrastructure_override' => true, 'name' => 'Unwanted change'])->assertSessionHasNoErrors();
        $this->assertSame('Core', $device->fresh()->name);
        $this->assertTrue($device->fresh()->isInfrastructure());
        $this->post(route('companies.sites.devices.store', [$company, $site]), [
            'name' => 'New device', 'device_model_id' => $model->id, 'infrastructure_override' => false,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', ['name' => 'New device', 'infrastructure_override' => false]);
        $type = ModelType::create(['name' => 'Switch']);
        $this->patch(route('model-types.update', $type), ['name' => 'Switch', 'is_infrastructure' => true])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->postJson(route('model-types.store'), ['name' => 'Router'])->assertCreated();
        $this->assertFalse(ModelType::where('name', 'Router')->sole()->is_infrastructure);
        $this->patch(route('model-types.update', $type), ['name' => 'Switch', 'is_infrastructure' => 'invalid'])
            ->assertSessionHasErrors('is_infrastructure');
        $this->assertFalse($type->fresh()->is_infrastructure);
    }

    public function test_device_images_can_be_created_replaced_removed_and_are_site_scoped(): void
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $otherSite = $company->sites()->create(['name' => 'Milano']);
        $manufacturer = Manufacturer::create(['name' => 'Vendor']);
        $model = $manufacturer->models()->create(['name' => 'Switch']);
        $user = User::factory()->create();
        $company->users()->attach($user);
        $this->actingAs($user);
        $data = ['name' => 'Switch', 'device_model_id' => $model->id];
        $this->post(route('companies.sites.devices.store', [$company, $site]), [
            ...$data, 'image' => UploadedFile::fake()->image('front.png'),
        ])->assertSessionHasNoErrors();
        $device = $site->devices()->sole();
        $original = $device->image_path;
        Storage::disk('local')->assertExists($original);
        $imageUrl = route('companies.sites.devices.image', [$company, $site, $device]);
        $this->get($imageUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('companies.sites.devices.image', [$company, $otherSite, $device]))->assertNotFound();
        $this->get(route('companies.sites.devices.index', [$company, $site]))->assertInertia(fn (Assert $page) => $page
            ->where('devices.0.image_url', $imageUrl)->missing('devices.0.image_path'));

        $update = route('companies.sites.devices.update', [$company, $site, $device]);
        $this->post($update, [...$data, '_method' => 'PUT', 'image' => UploadedFile::fake()->create('invalid.txt', 1, 'text/plain')])
            ->assertSessionHasErrors('image');
        $this->assertSame($original, $device->fresh()->image_path);
        $this->post($update, [...$data, '_method' => 'PUT', 'image' => UploadedFile::fake()->image('back.jpg')])
            ->assertSessionHasNoErrors();
        $replacement = $device->fresh()->image_path;
        Storage::disk('local')->assertMissing($original);
        Storage::disk('local')->assertExists($replacement);
        $this->put($update, $data)->assertSessionHasNoErrors();
        $this->assertSame($replacement, $device->fresh()->image_path);

        $this->actingAs(User::factory()->create());
        $this->get($imageUrl)->assertForbidden();
        $this->actingAs($user);
        $this->put($update, [...$data, 'remove_image' => true])->assertSessionHasNoErrors();
        $this->assertNull($device->fresh()->image_path);
        Storage::disk('local')->assertMissing($replacement);
        $this->get($imageUrl)->assertNotFound();
    }

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

    public function test_deleting_an_interface_or_device_frees_only_its_ips_and_preserves_exclusions(): void
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
            $this->post($store, ['ip' => $address, 'device_id' => $device->id, 'device_interface_id' => $interface->id])
                ->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('ip_addresses', 2);
        $this->assertSame('AA:BB:CC:DD:EE:01', $interface->fresh()->mac_address);
        $ips = $interface->ipAddresses()->get();
        $this->assertSame(['AA:BB:CC:DD:EE:01', 'AA:BB:CC:DD:EE:01'], $ips->pluck('mac_address')->all());
        $tag = $company->tags()->create(['name' => 'Management', 'color' => 'blue']);
        $ips->each(fn ($ip) => $ip->tags()->attach($tag));
        $range = $subnet->excludedRanges()->create(['start_address' => ip2long('10.0.0.1'), 'end_address' => ip2long('10.0.0.1')]);
        $standalone = $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.3'), 'hostname' => 'standalone']);
        $this->get(route('companies.sites.search.index', [$company, $site, 'search' => 'Server']))
            ->assertInertia(fn (Assert $page) => $page->where('results.total', 2));
        $deleteInterface = route('companies.sites.devices.interfaces.destroy', [$company, $site, $device, $interface]);
        $this->delete($deleteInterface, ['confirmed' => true, 'ip_ids' => [$ips[0]->id]])->assertSessionHasErrors('confirmed');
        $this->assertModelExists($interface);
        $this->assertModelExists($ips[1]);
        $this->delete($deleteInterface, ['confirmed' => true, 'ip_ids' => $ips->modelKeys()])
            ->assertSessionHasNoErrors();
        $this->assertModelMissing($interface);
        $ips->each(fn ($ip) => $this->assertModelMissing($ip));
        $this->assertDatabaseCount('ip_address_tag', 0);
        $this->assertModelExists($tag);
        $this->assertModelExists($range);
        $this->post($store, ['ip' => '10.0.0.2', 'device_id' => $device->id])
            ->assertSessionHasNoErrors();
        $deviceIp = $device->ipAddresses()->sole();
        $impact = $this->getJson(route('companies.sites.devices.deletion-impact', [$company, $site, $device]))->assertOk()->json();
        $this->delete(route('companies.sites.devices.destroy', [$company, $site, $device]), ['confirmed' => true, 'impact_token' => $impact['impact_token']])
            ->assertSessionHasNoErrors();
        $this->assertModelMissing($device);
        $this->assertModelMissing($deviceIp);
        $this->assertModelExists($standalone);
        $this->assertModelExists($range);
        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_clearing_an_ip_assignment_requires_confirmed_release_instead_of_preserving_metadata(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $device = $site->devices()->create(['name' => 'Server', 'manufacturer_id' => $vendor->id]);
        $interface = $device->interfaces()->create(['name' => 'eth0', 'mac_address' => 'AA:BB:CC:DD:EE:01']);
        $ip = $subnet->ipAddresses()->create([
            'address' => ip2long('10.0.0.1'), 'hostname' => 'server', 'description' => 'Management',
            'device_id' => $device->id, 'device_interface_id' => $interface->id, 'mac_address' => $interface->mac_address,
        ]);
        $tag = $company->tags()->create(['name' => 'Server', 'color' => 'blue']);
        $ip->tags()->attach($tag);
        $update = route('companies.sites.subnets.ip-addresses.update', [$company, $site, $subnet, $ip]);
        $delete = route('companies.sites.subnets.ip-addresses.destroy', [$company, $site, $subnet, $ip]);
        $this->patch($update, ['ip' => '10.0.0.1', 'hostname' => 'server', 'device_id' => $device->id])->assertSessionHasErrors('device_interface_id');
        $this->patch($update, ['ip' => '10.0.0.1', 'hostname' => 'server'])->assertSessionHasErrors('device_id');
        $this->post(route('companies.sites.devices.ip-addresses.store', [$company, $site, $device]), ['ip_address_id' => $ip->id])
            ->assertSessionHasErrors('device_interface_id');
        $this->delete($delete)->assertSessionHasErrors('confirmed');
        $this->assertSame($interface->id, $ip->fresh()->device_interface_id);
        $this->assertDatabaseHas('ip_address_tag', ['ip_address_id' => $ip->id, 'tag_id' => $tag->id]);
        $this->delete($delete, ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertModelMissing($ip);
        $this->assertDatabaseCount('ip_address_tag', 0);
        $this->assertModelExists($tag);
        $this->assertSame('AA:BB:CC:DD:EE:01', $interface->fresh()->mac_address);
        $this->post(route('companies.sites.subnets.ip-addresses.store', [$company, $site, $subnet]), ['ip' => '10.0.0.1', 'hostname' => 'new-host'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ip_addresses', ['address' => ip2long('10.0.0.1'), 'hostname' => 'new-host', 'mac_address' => null, 'device_id' => null]);
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
        $this->delete(route('manufacturers.models.destroy', [$manufacturer, $model]), ['confirmed' => true])->assertSessionHasErrors('impact_token');
        $this->assertModelExists($device);
        $this->assertModelExists($model);
    }

    public function test_model_deletion_requires_current_global_impact_and_removes_only_its_devices(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['is_superadmin' => true]);
        $manufacturer = Manufacturer::create(['name' => 'Vendor']);
        $model = $manufacturer->models()->create(['name' => 'Switch', 'assets' => ['front' => 'models/front.png']]);
        Storage::disk('local')->put('models/front.png', 'image');
        $devices = collect(['Alfa', 'Beta'])->map(function ($name) use ($manufacturer, $model) {
            $company = Company::create(['name' => $name]);
            $site = $company->sites()->create(['name' => null]);
            $device = $site->devices()->create(['name' => 'SW-'.$name, 'manufacturer_id' => $manufacturer->id, 'device_model_id' => $model->id]);
            $interface = $device->interfaces()->create(['name' => 'Management']);
            $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
            $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.1'), 'device_id' => $device->id, 'device_interface_id' => $interface->id]);

            return $device;
        });
        $site = $devices->first()->site;
        $unrelated = $site->devices()->create(['name' => 'Unrelated', 'manufacturer_id' => $manufacturer->id]);
        $subnet = $site->subnets()->sole();
        $standalone = $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.2'), 'hostname' => 'standalone']);
        $range = $subnet->excludedRanges()->create(['start_address' => ip2long('10.0.0.1'), 'end_address' => ip2long('10.0.0.1')]);
        $preview = route('manufacturers.models.deletion-impact', [$manufacturer, $model]);
        $delete = route('manufacturers.models.destroy', [$manufacturer, $model]);
        $member = User::factory()->create();
        $site->company->users()->attach($member);
        $this->actingAs($member)->getJson($preview)->assertForbidden();
        $this->delete($delete, ['confirmed' => true])->assertForbidden();

        $this->actingAs($admin);
        $impact = $this->getJson($preview)->assertOk()->assertJsonCount(2, 'devices')
            ->assertJsonPath('devices.0.company.name', 'Alfa')->assertJsonPath('devices.1.company.name', 'Beta')->json();
        $devices->first()->interfaces()->create(['name' => 'New interface']);
        $this->delete($delete, ['confirmed' => true, 'impact_token' => $impact['impact_token']])->assertSessionHasErrors('impact_token');
        $this->assertModelExists($model);
        $devices->each(fn ($device) => $this->assertModelExists($device));
        Storage::disk('local')->assertExists('models/front.png');

        $impact = $this->getJson($preview)->assertOk()->json();
        $this->delete($delete, ['confirmed' => true, 'impact_token' => $impact['impact_token']])->assertSessionHasNoErrors();
        $this->assertModelMissing($model);
        $devices->each(fn ($device) => $this->assertModelMissing($device));
        $this->assertDatabaseCount('device_interfaces', 0);
        $this->assertDatabaseCount('ip_addresses', 1);
        $this->assertModelExists($standalone);
        $this->assertModelExists($range);
        $this->assertModelExists($unrelated);
        $this->assertModelExists($manufacturer);
        Storage::disk('local')->assertMissing('models/front.png');
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

    public function test_mac_changes_are_atomic_confirmed_and_shared_in_both_directions(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $device = $site->devices()->create(['name' => 'Server', 'manufacturer_id' => $vendor->id]);
        $interface = $device->interfaces()->create(['name' => 'eth0', 'kind' => 'physical', 'mac_address' => 'AA:BB:CC:DD:EE:01']);
        $ips = collect([1, 2])->map(fn ($last) => $subnet->ipAddresses()->create([
            'address' => ip2long('10.0.0.'.$last), 'device_id' => $device->id, 'device_interface_id' => $interface->id, 'mac_address' => 'AA:BB:CC:DD:EE:01',
        ]));
        $interfaceUrl = route('companies.sites.devices.interfaces.update', [$company, $site, $device, $interface]);
        $change = ['name' => 'eth0', 'kind' => 'physical', 'mac_address' => 'AA:BB:CC:DD:EE:02'];

        $preview = $this->patchJson($interfaceUrl, $change)->assertUnprocessable()->assertJsonValidationErrors('mac_confirmation');
        $this->assertSame('AA:BB:CC:DD:EE:01', $interface->fresh()->mac_address);
        $this->assertSame(['AA:BB:CC:DD:EE:01', 'AA:BB:CC:DD:EE:01'], $interface->ipAddresses()->orderBy('id')->pluck('mac_address')->all());
        $this->patchJson($interfaceUrl, [...$change, 'mac_confirmation_token' => $preview->json('errors.mac_confirmation_token.0')])->assertRedirect();
        $this->assertSame('AA:BB:CC:DD:EE:02', $interface->fresh()->mac_address);
        $this->assertSame(['AA:BB:CC:DD:EE:02', 'AA:BB:CC:DD:EE:02'], $interface->ipAddresses()->orderBy('id')->pluck('mac_address')->all());

        $ipUrl = route('companies.sites.subnets.ip-addresses.update', [$company, $site, $subnet, $ips[0]]);
        $change = ['ip' => '10.0.0.1', 'device_id' => $device->id, 'device_interface_id' => $interface->id, 'mac_address' => 'AA:BB:CC:DD:EE:03'];
        $preview = $this->patchJson($ipUrl, $change)->assertUnprocessable();
        $this->patchJson($ipUrl, [...$change, 'mac_confirmation_token' => $preview->json('errors.mac_confirmation_token.0')])->assertRedirect();
        $this->assertSame('AA:BB:CC:DD:EE:03', $interface->fresh()->mac_address);
        $this->assertSame(['AA:BB:CC:DD:EE:03', 'AA:BB:CC:DD:EE:03'], $interface->ipAddresses()->orderBy('id')->pluck('mac_address')->all());

        $this->delete(route('companies.sites.devices.ip-addresses.destroy', [$company, $site, $device, $ips[0]]))->assertSessionHasErrors('confirmed');
        $this->assertModelExists($ips[0]);
        $this->delete(route('companies.sites.devices.ip-addresses.destroy', [$company, $site, $device, $ips[0]]), ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertModelMissing($ips[0]);
        $this->postJson(route('companies.sites.subnets.ip-addresses.store', [$company, $site, $subnet]), ['ip' => '10.0.0.1', 'device_id' => $device->id, 'mac_address' => 'AA:BB:CC:DD:EE:04'])->assertRedirect();
        $this->assertSame('AA:BB:CC:DD:EE:03', $interface->fresh()->mac_address);
    }

    public function test_link_conflicts_reject_stale_confirmation_and_never_rewrite_other_interfaces(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $user = User::factory()->create();
        $company->users()->attach($user);
        $this->actingAs($user);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $device = $site->devices()->create(['name' => 'Server', 'manufacturer_id' => $vendor->id]);
        $interface = $device->interfaces()->create(['name' => 'eth0', 'kind' => 'physical', 'mac_address' => 'AA:BB:CC:DD:EE:01']);
        $other = $device->interfaces()->create(['name' => 'eth1', 'kind' => 'physical', 'mac_address' => 'AA:BB:CC:DD:EE:09']);
        $existing = $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.1'), 'device_id' => $device->id, 'device_interface_id' => $interface->id, 'mac_address' => 'AA:BB:CC:DD:EE:01']);
        $incoming = $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.2'), 'hostname' => 'server', 'mac_address' => 'AA:BB:CC:DD:EE:02']);
        $url = route('companies.sites.devices.ip-addresses.store', [$company, $site, $device]);
        $data = ['ip_address_id' => $incoming->id, 'device_interface_id' => $interface->id];

        $preview = $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('mac_confirmation');
        $this->assertNull($incoming->fresh()->device_id);
        $this->assertSame('AA:BB:CC:DD:EE:01', $existing->fresh()->mac_address);
        // Another operator changes a value after the first comparison was displayed.
        $interface->update(['mac_address' => 'AA:BB:CC:DD:EE:03']);
        $stale = $this->postJson($url, [...$data, 'mac_confirmation_token' => $preview->json('errors.mac_confirmation_token.0')])->assertUnprocessable();
        $this->assertSame('AA:BB:CC:DD:EE:03', $interface->fresh()->mac_address);
        $this->assertNull($incoming->fresh()->device_id);
        $this->postJson($url, [...$data, 'mac_confirmation_token' => $stale->json('errors.mac_confirmation_token.0')])->assertRedirect();
        $this->assertDatabaseHas('ip_addresses', ['id' => $incoming->id, 'device_id' => $device->id, 'device_interface_id' => $interface->id, 'mac_address' => 'AA:BB:CC:DD:EE:02']);
        $this->assertSame('AA:BB:CC:DD:EE:02', $existing->fresh()->mac_address);
        $this->assertSame('AA:BB:CC:DD:EE:02', $interface->fresh()->mac_address);
        $this->assertSame('AA:BB:CC:DD:EE:09', $other->fresh()->mac_address);

        $ipUrl = route('companies.sites.subnets.ip-addresses.update', [$company, $site, $subnet, $incoming]);
        $move = ['ip' => '10.0.0.2', 'device_id' => $device->id, 'device_interface_id' => $other->id, 'mac_address' => 'AA:BB:CC:DD:EE:02'];
        $this->patchJson($ipUrl, $move)->assertUnprocessable()->assertJsonValidationErrors('mac_confirmation');
        $this->assertSame($interface->id, $incoming->fresh()->device_interface_id);
        $this->getJson(route('companies.sites.devices.interfaces.show', [$company, $site, $device, $interface]))->assertOk()->assertJsonCount(2, 'ips');
        $foreignSite = $company->sites()->create(['name' => 'Milano']);
        $foreignDevice = $foreignSite->devices()->create(['name' => 'Foreign', 'manufacturer_id' => $vendor->id]);
        $foreignInterface = $foreignDevice->interfaces()->create(['name' => 'eth0']);
        $this->getJson(route('companies.sites.devices.interfaces.show', [$company, $site, $device, $foreignInterface]))->assertNotFound();
        $this->postJson($url, [...$data, 'device_interface_id' => $foreignInterface->id])->assertUnprocessable()->assertJsonValidationErrors('device_interface_id');
        $this->actingAs(User::factory()->create())->getJson(route('companies.sites.devices.interfaces.show', [$company, $site, $device, $interface]))->assertForbidden();
    }

    public function test_subnet_and_company_search_use_model_port_labels_and_sort_associations(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $panel = $this->panel();
        $panel['faces']['front']['ports'][0]['label'] = 'ether1';
        $model = $vendor->models()->create(['name' => 'Switch', 'panel' => $panel]);
        $device = $site->devices()->create(['name' => 'SW-CORE', 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id]);
        $device->addModelInterfaces($model);
        $interface = $device->interfaces()->sole();
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.200'), 'device_id' => $device->id, 'device_interface_id' => $interface->id]);
        $url = route('companies.sites.subnets.show', [$company, $site, $subnet]);

        $this->get($url.'?search=ether1&sort=device&direction=asc')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 1)->where('ipAddresses.data.0.assignment_label', 'SW-CORE@ether1'));
        $this->get($url.'?sort=device&direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 254)->where('ipAddresses.data.0.ip', '10.0.0.200')->where('ipAddresses.data.1.ip', '10.0.0.1'));
        $this->get(route('companies.sites.search.index', [$company, $site, 'search' => 'ether1', 'sort' => 'device']))
            ->assertInertia(fn (Assert $page) => $page->where('results.total', 1)->where('results.data.0.assignment_label', 'SW-CORE@ether1')->where('results.data.0.device.id', $device->id));
        $this->get(route('companies.sites.devices.show', [$company, $site, $device, 'interface' => $interface->id]))
            ->assertInertia(fn (Assert $page) => $page->where('initialInterfaceId', $interface->id));
        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_model_port_mapping_is_saved_separately_from_labels_and_rejects_duplicates(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $vendor = Manufacturer::create(['name' => 'MikroTik']);
        $model = $vendor->models()->create(['name' => 'Synthetic router']);
        $panel = $this->panel();
        $panel['faces']['front']['image'] = null;
        $panel['faces']['rear']['image'] = null;
        $panel['faces']['front']['ports'][0]['mikrotikName'] = 'ether2';
        $url = route('manufacturers.models.panel.update', [$vendor, $model]);
        $this->postJson($url, ['document' => json_encode($panel), 'revision' => 0])->assertOk();
        $saved = $model->fresh()->panel['faces']['front']['ports'][0];
        $this->assertSame('1', $saved['label']);
        $this->assertSame('ether2', $saved['mikrotikName']);
        $panel['faces']['front']['ports'][] = [...$saved, 'id' => str_repeat('d', 32), 'label' => '2'];
        $this->postJson($url, ['document' => json_encode($panel), 'revision' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertCount(1, $model->fresh()->panel['faces']['front']['ports']);
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
