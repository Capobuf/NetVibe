<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\UnifiConnection;
use App\Models\UnifiSite;
use App\Models\User;
use App\Support\Ipv4Cidr;
use App\Support\UnifiInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UnifiInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_imports_once_and_preserves_manual_edits_and_deleted_devices(): void
    {
        Http::preventStrayRequests();
        $site = $this->source();
        $inventory = new UnifiInventory;
        $data = $this->device();
        $inventory->capture($site, [$data]);
        $this->assertDatabaseCount('devices', 0);

        DB::transaction(fn () => $inventory->activate($site));
        $source = $site->unifiDevices()->firstOrFail();
        $device = $source->device;
        $this->assertSame('Switch', $device->model->modelType->name);
        $this->assertNull($device->model->panel);
        $this->assertSame(2, $device->interfaces()->count());
        $this->assertSame(0, $device->interfaces()->whereNotNull('connector_id')->count());
        $device->update(['name' => 'Nome scelto dal tecnico']);
        $data['name'] = 'Nome successivo UniFi';
        DB::transaction(fn () => $inventory->capture($site, [$data]));
        $this->assertSame('Nome scelto dal tecnico', $device->fresh()->name);
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('device_interfaces', 2);
        $this->assertDatabaseCount('device_models', 1);
        $this->assertDatabaseCount('manufacturers', 1);

        DB::table('devices')->where('id', $device->id)->delete();
        DB::transaction(fn () => $inventory->capture($site, [$data]));
        $this->assertDatabaseCount('devices', 0);
        $this->assertSame('linked', $source->fresh()->status);
        $this->assertNull($source->fresh()->device_id);
        Http::assertNothingSent();
    }

    public function test_plausible_local_device_stays_pending_and_explicit_link_preserves_its_fields(): void
    {
        $site = $this->source();
        $manufacturer = Manufacturer::create(['name' => 'Ubiquiti']);
        $model = $manufacturer->models()->create(['name' => 'Modello documentato']);
        $device = $site->site->devices()->create(['name' => 'Switch rack', 'manufacturer_id' => $manufacturer->id,
            'device_model_id' => $model->id, 'asset_tag' => 'ASSET-42', 'notes' => 'Note locali']);
        $interface = $device->interfaces()->create(['kind' => 'physical', 'name' => 'Porta locale', 'mac_address' => '00:11:22:33:44:55']);
        $inventory = new UnifiInventory;
        $inventory->capture($site, [$this->device()]);

        DB::transaction(fn () => $inventory->activate($site));
        $source = $site->unifiDevices()->firstOrFail();
        $this->assertNull($source->device_id);
        $this->assertDatabaseCount('devices', 1);
        DB::transaction(fn () => $inventory->link($source, $device));

        $this->assertSame($model->id, $device->fresh()->device_model_id);
        $this->assertSame('ASSET-42', $device->fresh()->asset_tag);
        $this->assertSame('Note locali', $device->fresh()->notes);
        $this->assertSame('00:11:22:33:44:55', $interface->fresh()->mac_address);
        $this->assertDatabaseCount('device_interfaces', 1);
        $this->assertNull($source->fresh()->ports[0]['device_interface_id']);
    }

    public function test_relink_requires_confirmation_and_keeps_the_old_identity_ignored(): void
    {
        $site = $this->source();
        $inventory = new UnifiInventory;
        $inventory->capture($site, [$this->device()]);
        DB::transaction(fn () => $inventory->activate($site));
        $old = $site->unifiDevices()->firstOrFail();
        $device = $old->device;
        $replacement = [...$this->device(), 'id' => '00000000-0000-0000-0000-000000000022'];
        DB::transaction(fn () => $inventory->capture($site, [$replacement]));
        $source = $site->unifiDevices()->where('external_id', $replacement['id'])->firstOrFail();

        try {
            DB::transaction(fn () => $inventory->link($source, $device));
            $this->fail('Replacing an existing source requires confirmation.');
        } catch (ValidationException) {
            $this->assertSame($device->id, $old->fresh()->device_id);
        }
        DB::transaction(fn () => $inventory->link($source, $device, true));

        $this->assertSame($device->id, $source->fresh()->device_id);
        $this->assertNull($old->fresh()->device_id);
        $this->assertSame('ignored', $old->fresh()->status);
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('device_interfaces', 2);
    }

    public function test_model_mapping_reuses_imported_interfaces_and_preserves_documented_data(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $site = $this->source();
        $site->update(['sync_status' => 'ok']);
        $inventory = new UnifiInventory;
        $inventory->capture($site, [$this->device()]);
        DB::transaction(fn () => $inventory->activate($site));
        $source = $site->unifiDevices()->sole();
        $device = $source->device;
        $model = $device->model;
        $interface = $device->interfaces()->findOrFail($source->ports[0]['device_interface_id']);
        $interface->update(['name' => 'Management', 'mac_address' => '02:00:00:00:00:01']);
        $subnet = $site->site->subnets()->create(Ipv4Cidr::parse('192.168.60.0/24'));
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('192.168.60.20'),
            'device_id' => $device->id, 'device_interface_id' => $interface->id]);
        $panel = $this->panel();
        $model->update(['panel' => $panel]);
        $device->addModelInterfaces($model);
        $preview = route('manufacturers.models.unifi-ports', [$model->manufacturer_id, $model]);
        $ports = array_map(fn ($port) => array_intersect_key($port, array_flip(['id', 'type', 'label'])), $panel['faces']['front']['ports']);
        $url = route('manufacturers.models.panel.update', [$model->manufacturer_id, $model]);
        $seenAt = $source->synced_at;

        $this->postJson($preview, ['ports' => $ports])->assertOk()->assertJsonPath('observed', true)
            ->assertJsonPath('matches.'.str_repeat('a', 32), '1')->assertJsonPath('matches.'.str_repeat('b', 32), '2');
        $this->postJson($url, ['document' => json_encode($panel), 'revision' => 0])->assertOk();

        $this->assertSame(str_repeat('a', 32), $interface->fresh()->connector_id);
        $this->assertSame('Management', $interface->fresh()->name);
        $this->assertSame('02:00:00:00:00:01', $interface->fresh()->mac_address);
        $this->assertSame($interface->id, $ip->fresh()->device_interface_id);
        $this->assertSame(2, $device->interfaces()->count());
        $this->assertTrue($source->fresh()->ports[0]['interface_from_model']);
        $this->assertTrue($seenAt->equalTo($source->fresh()->synced_at));
        DB::transaction(fn () => $inventory->capture($site, [$this->device()]));
        $this->assertSame($interface->id, $source->fresh()->ports[0]['device_interface_id']);
        $this->assertSame(2, $device->interfaces()->count());

        $panel['faces']['front']['ports'][0]['unifiPort'] = null;
        $this->postJson($url, ['document' => json_encode($panel), 'revision' => 1])->assertOk();
        DB::transaction(fn () => $inventory->capture($site, [$this->device()]));
        $this->assertNull($source->fresh()->ports[0]['device_interface_id']);
        $this->assertSame($interface->id, $ip->fresh()->device_interface_id);
        Http::assertNothingSent();
    }

    public function test_model_corrections_preserve_manual_missing_and_offline_port_links(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $site = $this->source();
        $site->update(['sync_status' => 'ok']);
        $inventory = new UnifiInventory;
        $data = $this->device();
        $data['ports'][1]['connector'] = 'RJ45';
        $inventory->capture($site, [$data]);
        DB::transaction(fn () => $inventory->activate($site));
        $source = $site->unifiDevices()->sole();
        $device = $source->device;
        $model = $device->model;
        $panel = $this->panel();
        $panel['faces']['front']['ports'][1]['type'] = 'rj45';
        $url = route('manufacturers.models.panel.update', [$model->manufacturer_id, $model]);
        $this->postJson($url, ['document' => json_encode($panel), 'revision' => 0])->assertOk();
        $ids = array_column($source->fresh()->ports, 'device_interface_id');
        $panel['faces']['front']['ports'][0]['unifiPort'] = 2;
        $panel['faces']['front']['ports'][1]['unifiPort'] = 1;

        $this->postJson($url, ['document' => json_encode($panel), 'revision' => 1])->assertOk();
        $this->assertSame([$ids[1], $ids[0]], array_column($source->fresh()->ports, 'device_interface_id'));
        $site->update(['sync_status' => 'offline']);
        $panel['faces']['front']['ports'][0]['unifiPort'] = null;
        $this->postJson($url, ['document' => json_encode($panel), 'revision' => 2])->assertOk();
        $this->assertSame($ids[0], $source->fresh()->ports[1]['device_interface_id']);

        $site->update(['sync_status' => 'ok']);
        $onlyFirst = $data;
        $onlyFirst['ports'] = [$data['ports'][0]];
        DB::transaction(fn () => $inventory->capture($site, [$onlyFirst]));
        $this->assertTrue($source->fresh()->ports[1]['missing']);
        $this->assertSame($ids[0], $source->fresh()->ports[1]['device_interface_id']);

        $manual = $device->interfaces()->create(['kind' => 'physical', 'name' => 'Manual port']);
        DB::transaction(fn () => $inventory->mapPort($source->fresh(), 1, $manual->id));
        DB::transaction(fn () => $inventory->capture($site, [$data]));
        $this->assertSame($manual->id, $source->fresh()->ports[0]['device_interface_id']);
        $this->assertFalse($source->fresh()->ports[0]['interface_from_model']);
        $this->assertNull($source->fresh()->ports[1]['device_interface_id']);
    }

    public function test_unifi_model_mapping_rejects_duplicate_incompatible_and_unauthorized_choices(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $site = $this->source();
        $site->update(['sync_status' => 'ok']);
        $inventory = new UnifiInventory;
        $inventory->capture($site, [$this->device()]);
        DB::transaction(fn () => $inventory->activate($site));
        $model = $site->unifiDevices()->sole()->device->model;
        $panel = $this->panel();
        $url = route('manufacturers.models.panel.update', [$model->manufacturer_id, $model]);
        $panel['faces']['front']['ports'][0]['unifiPort'] = 2;
        $this->postJson($url, ['document' => json_encode($panel), 'revision' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('document');
        $panel['faces']['front']['ports'][0]['unifiPort'] = 1;
        $panel['faces']['front']['ports'][1]['type'] = 'rj45';
        $panel['faces']['front']['ports'][1]['unifiPort'] = 1;
        $this->postJson($url, ['document' => json_encode($panel), 'revision' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertNull($model->fresh()->panel);
        $this->actingAs(User::factory()->create(['is_superadmin' => false]))
            ->postJson(route('manufacturers.models.unifi-ports', [$model->manufacturer_id, $model]), ['ports' => []])->assertForbidden();
    }

    private function panel(): array
    {
        $face = ['ports' => [], 'image' => null, 'order' => [], 'shellState' => ['hidden' => false, 'locked' => false]];
        $port = ['id' => str_repeat('a', 32), 'type' => 'rj45', 'label' => '1', 'x' => 50, 'y' => 50,
            'rotation' => 0, 'scale' => 1, 'labelPosition' => 'center', 'poe' => false, 'group' => null,
            'opacity' => 1, 'opacityCustomized' => false, 'borderColor' => '#000000', 'hidden' => false, 'locked' => false];

        return ['version' => 3, 'name' => 'Synthetic panel',
            'shell' => ['x' => 0, 'y' => 0, 'width' => 200, 'height' => 100, 'radius' => 0, 'border' => 1],
            'faces' => ['front' => [...$face, 'ports' => [$port, [...$port, 'id' => str_repeat('b', 32), 'label' => '2', 'type' => 'sfp', 'x' => 100]]], 'rear' => $face],
            'numbering' => ['next' => 3, 'prefix' => '']];
    }

    private function source(): UnifiSite
    {
        $site = Company::create(['name' => 'Synthetic company'])->sites()->create(['name' => 'Synthetic site']);
        $connection = UnifiConnection::create(['name' => 'Synthetic controller', 'mode' => 'direct',
            'server_url' => 'https://192.168.60.10', 'api_key' => 'synthetic-key', 'enabled' => true,
            'revision' => (string) Str::uuid()]);

        return $connection->unifiSites()->create(['console_id' => 'direct', 'console_name' => 'Synthetic controller',
            'external_site_id' => (string) Str::uuid(), 'name' => 'Synthetic source', 'version' => '10.1.84',
            'available' => true, 'discovered_at' => now(), 'site_id' => $site->id, 'confirmed_at' => now()]);
    }

    private function device(): array
    {
        return ['id' => '00000000-0000-0000-0000-000000000011', 'name' => 'Switch rack', 'model' => 'USW-24',
            'mac_address' => '00:11:22:33:44:55', 'ip_address' => '192.168.60.20', 'state' => 'ONLINE',
            'features' => ['switching'], 'firmware_version' => '7.1.0', 'ports' => [
                ['idx' => 1, 'connector' => 'RJ45', 'state' => 'UP', 'speed_mbps' => 1000, 'max_speed_mbps' => 1000, 'poe' => null],
                ['idx' => 2, 'connector' => 'SFP', 'state' => 'DOWN', 'speed_mbps' => null, 'max_speed_mbps' => 1000, 'poe' => null],
            ]];
    }
}
