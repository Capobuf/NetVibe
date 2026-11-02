<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\UnifiConnection;
use App\Models\UnifiSite;
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
