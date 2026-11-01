<?php

namespace Tests\Feature;

use App\Models\Cable;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Manufacturer;
use App\Models\ModelType;
use App\Models\Site;
use App\Models\SnipeItAsset;
use App\Models\SnipeItCompanyLink;
use App\Models\SnipeItConnection;
use App\Models\SnipeItModel;
use App\Models\SnipeItSetting;
use App\Models\SnipeItSync;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SnipeItSyncTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private SnipeItConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->company = Company::create(['name' => 'Alfa']);
        $this->site = $this->company->sites()->create(['name' => 'Torino']);
        $this->connection = SnipeItConnection::create(['server_url' => 'https://192.168.10.20', 'api_key' => 'test-key']);
        SnipeItSetting::findOrFail('global')->update(['connection_id' => $this->connection->id]);
        SnipeItSetting::forCompany($this->company)->save();
        SnipeItCompanyLink::create(['company_id' => $this->company->id, 'connection_id' => $this->connection->id,
            'external_company_id' => 7, 'external_company_name' => 'Alfa', 'confirmed_at' => now()]);
        Http::preventStrayRequests();
    }

    public function test_first_acquisition_is_paginated_company_scoped_and_requires_confirmation(): void
    {
        $this->server([$this->asset(1), $this->asset(2), $this->asset(3, ['company' => ['id' => 8], 'model' => ['id' => 99]])], pageSize: 2);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('snipe_it_assets', 2);
        $this->assertDatabaseCount('snipe_it_models', 1);
        $this->assertDatabaseCount('device_models', 0);
        $this->assertDatabaseCount('devices', 0);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/hardware?') && (int) $request['company_id'] === 7 && (int) $request['offset'] === 2);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' || str_contains($request->url(), '/models/99'));
        $first = SnipeItAsset::where('external_id', 1)->sole();
        $this->confirm('devices', [['id' => $first->id, 'action' => 'create', 'site_id' => $this->site->id]], true)->assertSessionHasNoErrors();
        $this->server([$this->asset(1), $this->asset(2), $this->asset(4, ['name' => ''])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('devices', 2);
        $this->assertDatabaseHas('devices', ['name' => 'TAG-4']);
        $this->assertNull(SnipeItAsset::where('external_id', 2)->sole()->device_id);
        $this->assertTrue(SnipeItAsset::where('external_id', 2)->sole()->needs_confirmation);
    }

    public function test_link_sync_and_detach_preserve_ip_interfaces_panel_rack_and_cables(): void
    {
        $this->server([$this->asset(1)]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $manufacturer = Manufacturer::create(['name' => 'Vendor']);
        $model = $manufacturer->models()->create(['name' => 'Model 1']);
        $panel = ['faces' => ['front' => ['ports' => [['id' => 'port-a', 'type' => 'rj45']]], 'rear' => ['ports' => []]]];
        $model->update(['panel' => $panel, 'rack_height_u' => 1]);
        $panel = $model->fresh()->panel;
        $device = $this->site->devices()->create(['name' => 'Manual', 'manufacturer_id' => $model->manufacturer_id, 'device_model_id' => $model->id, 'notes' => 'Keep notes']);
        $interface = $device->interfaces()->create(['connector_id' => 'port-a', 'name' => 'Keep alias', 'kind' => 'physical']);
        $subnet = $this->site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.10'), 'hostname' => 'keep-hostname', 'device_id' => $device->id, 'device_interface_id' => $interface->id]);
        $rack = $this->site->racks()->create(['name' => 'Rack', 'height_u' => 24, 'numbering' => 'bottom-up']);
        $item = $rack->items()->create(['key' => str_repeat('a', 32), 'kind' => 'device', 'device_id' => $device->id, 'position' => 0, 'height' => 1, 'width' => 100, 'x' => 0]);
        $cable = Cable::create(['site_id' => $this->site->id, 'type' => 'copper', 'color' => '#2563eb']);
        $end = $cable->ends()->create(['side' => 'a', 'device_id' => $device->id, 'connector_id' => 'port-a']);
        $asset = SnipeItAsset::sole();
        $this->confirm('devices', [['id' => $asset->id, 'action' => 'link', 'target_id' => $device->id]], true)->assertSessionHasNoErrors();
        $this->server([$this->asset(1, ['name' => 'Renamed', 'serial' => 'New serial'])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $device->fresh()->name);
        $this->assertSame($model->id, SnipeItModel::sole()->device_model_id);
        $this->assertDatabaseCount('device_models', 1);
        $this->patch(route('companies.sites.devices.update', [$this->company, $this->site, $device]), ['name' => 'Override', 'device_model_id' => $model->id, 'serial_number' => 'New serial'])
            ->assertSessionHasErrors('name');
        $this->server([$this->asset(1, ['model' => ['id' => 2, 'name' => 'Model 2']])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame($model->id, $device->fresh()->device_model_id);
        $this->assertNotNull($asset->fresh()->problem);
        $this->assertDatabaseCount('device_models', 1);
        $this->post($this->url('detach'), ['asset_id' => $asset->id, 'confirmed' => true, 'exclude' => true])->assertSessionHasNoErrors();
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame('excluded', $asset->fresh()->state);
        $this->assertNull($asset->fresh()->device_id);
        $this->assertSame('keep-hostname', $ip->fresh()->hostname);
        $this->assertSame($interface->id, $ip->fresh()->device_interface_id);
        $this->assertSame('Keep alias', $interface->fresh()->name);
        $this->assertSame('Keep notes', $device->fresh()->notes);
        $this->assertSame($panel, $model->fresh()->panel);
        $this->assertModelExists($item);
        $this->assertModelExists($end);
    }

    public function test_new_assets_wait_for_sites_or_manual_matches_and_bulk_assignments_are_company_scoped(): void
    {
        $this->activate();
        $otherSite = $this->company->sites()->create(['name' => 'Milano']);
        $foreign = Company::create(['name' => 'Beta'])->sites()->create(['name' => 'Foreign']);
        $model = DeviceModel::sole();
        $this->site->devices()->create(['name' => 'Device 4', 'manufacturer_id' => $model->manufacturer_id, 'device_model_id' => $model->id]);
        $this->server([$this->asset(1), $this->asset(2), $this->asset(3), $this->asset(4)]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $a = SnipeItAsset::where('external_id', 2)->sole();
        $b = SnipeItAsset::where('external_id', 3)->sole();
        $this->assertSame('site', $a->state);
        $this->assertTrue(SnipeItAsset::where('external_id', 4)->sole()->needs_confirmation);
        $this->confirm('sites', [['id' => $a->id, 'action' => 'create', 'site_id' => $foreign->id]])->assertSessionHasErrors('sync');
        $this->confirm('sites', [['id' => $a->id, 'action' => 'create', 'site_id' => $this->site->id], ['id' => $b->id, 'action' => 'create', 'site_id' => $otherSite->id]])->assertSessionHasNoErrors();
        $this->assertSame($this->site->id, $a->fresh()->device->site_id);
        $this->assertSame($otherSite->id, $b->fresh()->device->site_id);
        $this->assertNull(SnipeItAsset::where('external_id', 4)->sole()->device_id);
    }

    public function test_partial_reads_keep_the_snapshot_and_missing_assets_keep_the_device(): void
    {
        $this->activate();
        $asset = SnipeItAsset::sole();
        $before = $asset->snapshot;
        $success = SnipeItSync::sole()->synced_at;
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            $this->connection->server_url.'/api/v1/hardware*' => Http::sequence()->push(['total' => 2, 'rows' => [$this->asset(1, ['name' => 'Partial'])]])->push(['status' => 'error'], 200),
            $this->connection->server_url.'/api/v1/models/1' => Http::response($this->modelPayload(1)),
        ]);
        $this->post($this->url('sync'))->assertSessionHasErrors('sync');
        $this->assertSame($before, $asset->fresh()->snapshot);
        $this->assertEquals($success, SnipeItSync::sole()->synced_at);
        $this->assertTrue($asset->fresh()->present);
        $this->server([]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertFalse($asset->fresh()->present);
        $this->assertNotNull($asset->fresh()->device_id);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_json_key_order_does_not_block_matching_or_updated_models_but_local_changes_do(): void
    {
        $this->activate();
        $this->assertNull(SnipeItModel::sole()->problem);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertNull(SnipeItModel::sole()->problem);

        $this->server([$this->asset(1)], modelName: 'Updated upstream');
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame('Updated upstream', DeviceModel::sole()->name);
        $this->assertNull(SnipeItModel::sole()->problem);

        DeviceModel::sole()->update(['name' => 'Edited locally']);
        $this->server([$this->asset(1)], modelName: 'Changed again');
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame('Edited locally', DeviceModel::sole()->name);
        $this->assertNotNull(SnipeItModel::sole()->problem);
    }

    public function test_catalog_shared_with_manual_devices_and_new_connection_identity_are_preserved(): void
    {
        $this->activate();
        $model = DeviceModel::sole();
        $beta = Company::create(['name' => 'Beta']);
        $beta->sites()->create(['name' => 'Sede'])->devices()->create(['name' => 'Manual Beta', 'manufacturer_id' => $model->manufacturer_id, 'device_model_id' => $model->id]);
        $this->server([$this->asset(1)], modelName: 'Changed upstream');
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame('Model 1', $model->fresh()->name);
        $this->assertNotNull(SnipeItModel::sole()->problem);
        $old = SnipeItAsset::sole();
        $this->connection = SnipeItConnection::create(['server_url' => 'https://192.168.10.21', 'api_key' => 'other-key']);
        SnipeItSetting::findOrFail('global')->update(['connection_id' => $this->connection->id]);
        $this->post($this->url('sync'))->assertSessionHasErrors('sync');
        SnipeItCompanyLink::create(['company_id' => $this->company->id, 'connection_id' => $this->connection->id, 'external_company_id' => 7, 'external_company_name' => 'Other instance', 'confirmed_at' => now()]);
        $this->server([$this->asset(1)]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertNotNull($old->fresh()->device_id);
        $this->assertDatabaseCount('snipe_it_assets', 2);
        $this->assertNull(SnipeItAsset::whereKeyNot($old->id)->sole()->device_id);
        $this->assertDatabaseCount('device_models', 1);
        $new = SnipeItAsset::whereKeyNot($old->id)->sole();
        $this->confirm('devices', [['id' => $new->id, 'action' => 'create', 'site_id' => $this->site->id]], true)->assertSessionHasNoErrors();
        $this->assertSame($model->id, $new->fresh()->device->device_model_id);
        $this->assertDatabaseCount('device_models', 1);
    }

    public function test_company_users_cannot_read_or_mutate_sync_and_stale_confirmation_is_rejected(): void
    {
        $this->activate();
        $this->post($this->url('confirm'), ['sync_id' => SnipeItSync::sole()->id, 'revision' => 0, 'view' => 'devices', 'activate' => true, 'decisions' => []])->assertSessionHasErrors('sync');
        $user = User::factory()->create(['is_superadmin' => false]);
        $this->company->users()->attach($user);
        $this->actingAs($user);
        foreach (['records', 'options'] as $action) {
            $this->get($this->url($action))->assertForbidden();
        }
        foreach (['sync', 'confirm', 'detach'] as $action) {
            $this->post($this->url($action))->assertForbidden();
        }
        $this->get(route('companies.sites.devices.show', [$this->company, $this->site, Device::sole()]))->assertOk()->assertDontSee('test-key');
    }

    public function test_catalog_is_created_only_for_imported_devices_including_later_automatic_imports(): void
    {
        $deferred = $this->asset(2, ['model' => ['id' => 2, 'name' => 'Deferred'], 'manufacturer' => ['name' => 'Deferred vendor']]);
        $excluded = $this->asset(3, ['model' => ['id' => 3, 'name' => 'Excluded'], 'manufacturer' => ['name' => 'Excluded vendor']]);
        $this->server([$this->asset(1), $deferred, $excluded]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->confirm('devices', [
            ['id' => SnipeItAsset::where('external_id', 1)->sole()->id, 'action' => 'create', 'site_id' => $this->site->id],
            ['id' => SnipeItAsset::where('external_id', 3)->sole()->id, 'action' => 'exclude'],
        ], true)->assertSessionHasNoErrors();
        $new = $this->asset(4, ['model' => ['id' => 4, 'name' => 'Switch'], 'manufacturer' => ['name' => 'New vendor'], 'category' => ['name' => 'Switches']]);
        $this->server([$this->asset(1), $deferred, $excluded, $new]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('devices', 2);
        $this->assertDatabaseCount('device_models', 2);
        $this->assertDatabaseCount('manufacturers', 2);
        $this->assertSame('Switches', SnipeItAsset::where('external_id', 4)->sole()->device->model->modelType->name);
        $this->assertDatabaseMissing('manufacturers', ['name' => 'Deferred vendor']);
        $this->assertDatabaseMissing('manufacturers', ['name' => 'Excluded vendor']);

        $this->company->sites()->create(['name' => 'Milano']);
        $waiting = $this->asset(5, ['model' => ['id' => 5, 'name' => 'Router'], 'manufacturer' => ['name' => 'Waiting vendor'], 'category' => ['name' => 'Routers']]);
        $this->server([$this->asset(1), $deferred, $excluded, $new, $waiting]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $asset = SnipeItAsset::where('external_id', 5)->sole();
        $this->assertSame('site', $asset->state);
        $this->assertDatabaseMissing('manufacturers', ['name' => 'Waiting vendor']);
        $this->assertDatabaseMissing('model_types', ['name' => 'Routers']);
        $this->confirm('sites', [['id' => $asset->id, 'action' => 'create', 'site_id' => $this->site->id]])->assertSessionHasNoErrors();
        $this->assertSame('Waiting vendor', $asset->fresh()->device->manufacturer->name);
        $this->assertSame('Routers', $asset->fresh()->device->model->modelType->name);
    }

    public function test_missing_model_data_stays_pending_without_blocking_other_selected_devices(): void
    {
        $this->server([$this->asset(1), $this->asset(2, ['model' => null])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->confirm('devices', SnipeItAsset::orderByDesc('external_id')->get()->map(fn ($asset) => [
            'id' => $asset->id, 'action' => 'create', 'site_id' => $this->site->id,
        ])->all(), true)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('device_models', 1);
        $missing = SnipeItAsset::where('external_id', 2)->sole();
        $this->assertNull($missing->device_id);
        $this->assertSame('Modello mancante in Snipe-IT.', $missing->problem);
        $this->assertNotNull(SnipeItSync::sole()->activated_at);
    }

    public function test_different_local_model_is_resolved_in_device_confirmation_without_overwriting_it(): void
    {
        $manufacturer = Manufacturer::create(['name' => 'Local vendor']);
        $type = ModelType::create(['name' => 'Local type']);
        $model = $manufacturer->models()->create(['name' => 'Local model', 'model_type_id' => $type->id]);
        $device = $this->site->devices()->create(['name' => 'Device 1', 'manufacturer_id' => $manufacturer->id, 'device_model_id' => $model->id]);
        $this->server([$this->asset(1)]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $asset = SnipeItAsset::sole();
        $this->confirm('devices', [['id' => $asset->id, 'action' => 'link', 'target_id' => $device->id]])->assertSessionHasNoErrors();
        $this->assertNull($asset->fresh()->device_id);
        $this->assertNotNull($asset->fresh()->problem);
        $this->assertDatabaseCount('manufacturers', 1);
        $this->assertDatabaseCount('device_models', 1);

        $this->confirm('devices', [['id' => $asset->id, 'action' => 'link', 'target_id' => $device->id, 'model_id' => $model->id]], true)->assertSessionHasNoErrors();
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame($device->id, $asset->fresh()->device_id);
        $this->assertSame($model->id, $device->fresh()->device_model_id);
        $this->assertSame('Local model', $model->fresh()->name);
        $this->assertSame($type->id, $model->fresh()->model_type_id);
        $this->assertDatabaseCount('device_models', 1);
        $this->assertDatabaseMissing('manufacturers', ['name' => 'Vendor']);
    }

    public function test_records_group_by_source_type_and_import_a_larger_page_with_its_types(): void
    {
        $assets = collect(range(1, 52))->map(fn ($id) => $this->asset($id, [
            'model' => ['id' => $id % 2 + 1, 'name' => $id % 2 ? 'Display' : 'Desktop'],
            'category' => ['name' => $id % 2 ? 'Monitor' : 'Computer'],
        ]))->all();
        $this->server($assets);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();

        $this->get($this->url('records').'?per_page=50')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.per_page', 50)->where('rows.total', 52)->has('rows.data', 50)
            ->where('rows.data.0.model.category', 'Computer')
            ->where('rows.data.25.model.category', 'Computer')
            ->where('rows.data.26.model.category', 'Monitor'));
        $this->get($this->url('records').'?q=Monitor&per_page=50')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 26)->where('rows.data.0.model.category', 'Monitor'));
        $this->confirm('devices', SnipeItAsset::orderBy('id')->limit(50)->get()->map(fn ($asset) => [
            'id' => $asset->id, 'action' => 'create', 'site_id' => $this->site->id,
        ])->all())->assertSessionHasNoErrors();

        $this->assertDatabaseCount('devices', 50);
        $this->assertDatabaseCount('device_models', 2);
        $this->assertSame('Computer', DeviceModel::where('name', 'Desktop')->sole()->modelType->name);
        $this->assertSame('Monitor', DeviceModel::where('name', 'Display')->sole()->modelType->name);
    }

    private function activate(): void
    {
        $this->server([$this->asset(1)]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->confirm('devices', [['id' => SnipeItAsset::sole()->id, 'action' => 'create', 'site_id' => $this->site->id]], true)->assertSessionHasNoErrors();
    }

    private function confirm(string $view, array $decisions, bool $activate = false): TestResponse
    {
        $sync = SnipeItSync::current($this->company);

        return $this->post($this->url('confirm'), ['sync_id' => $sync->id, 'revision' => $sync->revision, 'view' => $view, 'decisions' => $decisions, 'activate' => $activate]);
    }

    private function url(string $action): string
    {
        return route('companies.settings.integrations.snipe-it.'.$action, $this->company);
    }

    private function asset(int $id, array $changes = []): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/snipe-it/hardware.json')), true)['rows'][0];

        return [...$row, 'id' => $id, 'name' => 'Device '.$id, 'asset_tag' => 'TAG-'.$id, 'serial' => 'SERIAL-'.$id,
            'model' => ['id' => 1, 'name' => 'Model 1'], 'manufacturer' => ['id' => 1, 'name' => 'Vendor'], 'category' => null, ...$changes];
    }

    private function modelPayload(int $id, ?string $name = null): array
    {
        // Shape verified against GET /models/{id}; values are synthetic.
        return ['id' => $id, 'name' => $name ?? 'Model '.$id, 'manufacturer' => ['id' => 1, 'name' => 'Vendor'], 'category' => null];
    }

    private function server(array $assets, int $pageSize = 100, ?string $modelName = null): void
    {
        if ($modelName !== null) {
            $assets = array_map(function ($asset) use ($modelName) {
                $asset['model']['name'] = $modelName;

                return $asset;
            }, $assets);
        }
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            $this->connection->server_url.'/api/v1/hardware*' => fn (Request $request) => Http::response(['total' => count($assets), 'rows' => array_slice($assets, (int) $request['offset'], $pageSize)]),
            $this->connection->server_url.'/api/v1/models/*' => fn (Request $request) => Http::response($this->modelPayload((int) basename(parse_url($request->url(), PHP_URL_PATH)), $modelName)),
        ]);
    }
}
