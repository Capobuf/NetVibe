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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
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

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_local_classification_can_be_edited_by_company_users_and_survives_sync(bool $override): void
    {
        $this->activate();
        $device = Device::sole();
        $type = ModelType::create(['name' => 'Imported type', 'is_infrastructure' => ! $override]);
        $device->model->update(['model_type_id' => $type->id]);
        $member = User::factory()->create();
        $this->company->users()->attach($member);
        $url = route('companies.sites.devices.classification', [$this->company, $this->site, $device]);
        $this->actingAs($member)->patch($url, ['infrastructure_override' => $override, 'name' => 'Local rename'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Device 1', $device->fresh()->name);
        $this->assertSame($override, $device->fresh()->isInfrastructure());
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->server([$this->asset(1, ['name' => 'Upstream rename', 'serial' => 'Updated serial'])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame('Upstream rename', $device->fresh()->name);
        $this->assertSame('Updated serial', $device->fresh()->serial_number);
        $this->assertSame($override, $device->fresh()->infrastructure_override);
        $this->assertSame($override, $device->fresh()->isInfrastructure());
        $this->actingAs($member)->patch($url, ['infrastructure_override' => null])->assertSessionHasNoErrors();
        $this->assertSame(! $override, $device->fresh()->isInfrastructure());
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_asset_images_are_synced_and_cannot_be_overridden_locally(): void
    {
        Storage::fake('local');
        $url = $this->connection->server_url.'/uploads/models/switch.png';
        $this->server([$this->asset(1, ['image' => $url])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $asset = SnipeItAsset::sole();
        $this->confirm('devices', [['id' => $asset->id, 'action' => 'create', 'site_id' => $this->site->id]])->assertSessionHasNoErrors();
        $device = $asset->fresh()->device;
        $index = route('companies.sites.devices.index', [$this->company, $this->site]);
        $this->get($index)->assertInertia(fn (Assert $page) => $page->where('devices.0.image_url', $url));
        $this->post(route('companies.sites.devices.update', [$this->company, $this->site, $device]), [
            '_method' => 'PUT', 'name' => $device->name, 'device_model_id' => $device->device_model_id,
            'image' => UploadedFile::fake()->image('override.png'),
        ])->assertSessionHasErrors('image');
        $this->assertNull($device->fresh()->image_path);
        $this->assertSame([], Storage::disk('local')->allFiles('devices'));

        $this->server([$this->asset(1, ['image' => 'javascript:alert(1)'])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->get($index)->assertInertia(fn (Assert $page) => $page->where('devices.0.image_url', null));
    }

    public function test_acquisition_is_company_scoped_and_only_explicitly_selected_devices_are_imported(): void
    {
        $this->server([$this->asset(1), $this->asset(2), $this->asset(3, ['company' => ['id' => 8], 'model' => ['id' => 99]])], pageSize: 2);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('snipe_it_assets', 2);
        $this->assertDatabaseCount('snipe_it_models', 1);
        $this->assertDatabaseCount('device_models', 0);
        $this->assertDatabaseCount('devices', 0);
        $this->assertNotNull(SnipeItSync::sole()->activated_at);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/hardware?') && (int) $request['company_id'] === 7 && (int) $request['offset'] === 2);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' || str_contains($request->url(), '/models/99'));
        $first = SnipeItAsset::where('external_id', 1)->sole();
        $this->confirm('devices', [['id' => $first->id, 'action' => 'create', 'site_id' => $this->site->id]])->assertSessionHasNoErrors();
        $this->server([$this->asset(1), $this->asset(2), $this->asset(4, ['name' => ''])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('devices', 1);
        $this->assertNull(SnipeItAsset::where('external_id', 2)->sole()->device_id);
        $fourth = SnipeItAsset::where('external_id', 4)->sole();
        $this->assertNull($fourth->device_id);
        $this->confirm('review', [['id' => $fourth->id, 'action' => 'create', 'site_id' => $this->site->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', ['name' => 'TAG-4']);
        $this->assertTrue(SnipeItAsset::where('external_id', 2)->sole()->needs_confirmation);
    }

    public function test_link_sync_and_detach_preserve_ip_interfaces_panel_rack_and_cables(): void
    {
        $manufacturer = Manufacturer::create(['name' => 'Vendor']);
        $model = $manufacturer->models()->create(['name' => 'Model 1']);
        $panel = ['faces' => ['front' => ['ports' => [['id' => 'port-a', 'type' => 'rj45']]], 'rear' => ['ports' => []]]];
        $model->update(['panel' => $panel, 'rack_height_u' => 1]);
        $panel = $model->fresh()->panel;
        $device = $this->site->devices()->create(['name' => 'Device 1', 'manufacturer_id' => $model->manufacturer_id, 'device_model_id' => $model->id, 'notes' => 'Keep notes']);
        $interface = $device->interfaces()->create(['connector_id' => 'port-a', 'name' => 'Keep alias', 'kind' => 'physical']);
        $subnet = $this->site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.10'), 'hostname' => 'keep-hostname', 'device_id' => $device->id, 'device_interface_id' => $interface->id]);
        $rack = $this->site->racks()->create(['name' => 'Rack', 'height_u' => 24, 'numbering' => 'bottom-up']);
        $item = $rack->items()->create(['key' => str_repeat('a', 32), 'kind' => 'device', 'device_id' => $device->id, 'position' => 0, 'height' => 1, 'width' => 100, 'x' => 0]);
        $cable = Cable::create(['site_id' => $this->site->id, 'type' => 'copper', 'color' => '#2563eb']);
        $end = $cable->ends()->create(['side' => 'a', 'device_id' => $device->id, 'connector_id' => 'port-a']);
        $this->server([$this->asset(1)]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
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
        $this->assertSame('pending', $a->state);
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
        foreach (['sync', 'confirm', 'detach', 'fields'] as $action) {
            $this->post($this->url($action))->assertForbidden();
        }
        $this->put($this->url('map-fields'))->assertForbidden();
        $this->get(route('companies.sites.devices.show', [$this->company, $this->site, Device::sole()]))->assertOk()->assertDontSee('test-key');
    }

    public function test_catalog_is_created_only_for_selected_devices_including_later_imports(): void
    {
        $secondSite = $this->company->sites()->create(['name' => 'Milano']);
        $deferred = $this->asset(2, ['model' => ['id' => 2, 'name' => 'Deferred'], 'manufacturer' => ['name' => 'Deferred vendor']]);
        $excluded = $this->asset(3, ['model' => ['id' => 3, 'name' => 'Excluded'], 'manufacturer' => ['name' => 'Excluded vendor']]);
        $this->server([$this->asset(1), $deferred, $excluded]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->confirm('devices', [
            ['id' => SnipeItAsset::where('external_id', 1)->sole()->id, 'action' => 'create', 'site_id' => $this->site->id],
            ['id' => SnipeItAsset::where('external_id', 3)->sole()->id, 'action' => 'exclude'],
        ], true)->assertSessionHasNoErrors();
        $secondSite->delete();
        $new = $this->asset(4, ['model' => ['id' => 4, 'name' => 'Switch'], 'manufacturer' => ['name' => 'New vendor'], 'category' => ['name' => 'Switches']]);
        $this->server([$this->asset(1), $deferred, $excluded, $new]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('devices', 1);
        $this->confirm('review', [['id' => SnipeItAsset::where('external_id', 4)->sole()->id, 'action' => 'create', 'site_id' => $this->site->id]])->assertSessionHasNoErrors();
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
        $this->assertSame('pending', $asset->state);
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
        ])->all())->assertSessionHasNoErrors();

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
        $this->company->sites()->create(['name' => 'Milano']);
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

    public function test_page_candidates_keep_sql_matching_and_company_isolation_with_bounded_queries(): void
    {
        $this->activate();
        $linked = SnipeItAsset::sole();
        $linked->device->update(['name' => 'Candidate']);
        $model = DeviceModel::sole();
        $external = SnipeItModel::create([
            'connection_id' => $this->connection->id, 'external_id' => 2,
            'snapshot' => ['name' => 'model 1', 'manufacturer' => 'vendor', 'category' => null],
        ]);
        $otherSite = $this->company->sites()->create(['name' => 'Milano']);
        $candidateIds = [];
        foreach ([
            ['name' => 'Candidate'],
            ['name' => 'Serial match', 'serial_number' => 'SHARED-SERIAL'],
            ['name' => 'Tag match', 'asset_tag' => 'SHARED-TAG'],
        ] as $attributes) {
            $candidateIds[] = $otherSite->devices()->create([
                ...$attributes, 'manufacturer_id' => $model->manufacturer_id, 'device_model_id' => $model->id,
            ])->id;
        }
        Company::create(['name' => 'Beta'])->sites()->create(['name' => 'Foreign'])->devices()->create([
            'name' => 'Candidate', 'manufacturer_id' => $model->manufacturer_id, 'serial_number' => 'SHARED-SERIAL', 'asset_tag' => 'SHARED-TAG',
        ]);
        foreach (range(2, 101) as $id) {
            $linked->sync->assets()->create([
                'external_id' => $id, 'snipe_it_model_id' => $external->id,
                'snapshot' => [...$linked->snapshot, 'name' => 'candidate', 'serial' => 'shared-serial', 'asset_tag' => 'shared-tag'],
                'state' => $id === 101 ? 'excluded' : 'pending', 'present' => true,
            ]);
        }

        Http::swap((new Factory)->preventStrayRequests());
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()];
        $queryCounts = [];
        foreach ([25, 100] as $size) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $response = $this->get($this->url('records').'?group=none&per_page='.$size, $headers);
                $queryCounts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            // The default view lists only assets still to import: the linked one is in its own view.
            $response->assertOk()->assertJsonPath('props.rows.total', 99)
                ->assertJsonCount($size, 'props.rows.data');
            foreach ($response->json('props.rows.data') as $row) {
                $ids = array_column($row['candidates'], 'id');
                sort($ids);
                $this->assertSame($candidateIds, $ids);
                $this->assertSame($model->id, $row['model_local']['id']);
                $this->assertFalse($row['model_linked']);
                $this->assertSame('pending', $row['state']);
                // Candidates are shown in the row itself and are no longer reported as a problem.
                $this->assertNull($row['problem']);
            }
        }
        $this->assertLessThanOrEqual($queryCounts[0] + 2, $queryCounts[1]);
        $this->get($this->url('records').'?view=excluded', $headers)
            ->assertOk()->assertJsonCount(1, 'props.rows.data')
            ->assertJsonPath('props.rows.data.0.candidates', [])
            ->assertJsonPath('props.rows.data.0.state', 'excluded')
            ->assertJsonPath('props.rows.data.0.model_local.id', $model->id);
        $this->assertDatabaseCount('devices', 5);
        Http::assertNothingSent();
    }

    public function test_ethernet_creates_and_links_ips_only_in_the_device_site_without_importing_wifi(): void
    {
        $subnet = $this->site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $otherSite = Company::create(['name' => 'Beta'])->sites()->create(['name' => 'Other']);
        $otherSubnet = $otherSite->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $otherIp = $otherSubnet->ipAddresses()->create(['address' => ip2long('10.0.0.10'), 'description' => 'Other tenant']);
        $fields = [
            'IP Address [eth]' => ['value' => '10.0.0.10'],
            'MAC Address [eth]' => ['value' => 'aa-bb-cc-dd-ee-01'],
            'IP Address [wlan]' => ['value' => '10.0.0.20'],
            'MAC Address [wlan]' => ['value' => 'AA:BB:CC:DD:EE:02'],
        ];
        $this->server([$this->asset(1, ['custom_fields' => $fields])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $asset = SnipeItAsset::sole();
        $this->confirm('devices', [['id' => $asset->id, 'action' => 'create', 'site_id' => $this->site->id]])->assertSessionHasNoErrors();
        $device = $asset->fresh()->device;
        $record = $subnet->ipAddresses()->sole();
        $this->assertSame($device->id, $record->device_id);
        $this->assertSame(ip2long('10.0.0.10'), $record->address);
        $this->assertSame('AA:BB:CC:DD:EE:01', $record->mac_address);
        $this->assertNull($otherIp->fresh()->device_id);

        $existing = $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.11'), 'hostname' => 'keep-hostname', 'description' => 'Keep notes']);
        $fields['IP Address [eth]']['value'] = '10.0.0.11';
        $this->server([$this->asset(1, ['custom_fields' => $fields])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ip_addresses', 3);
        $this->assertSame($device->id, $existing->fresh()->device_id);
        $this->assertSame('keep-hostname', $existing->fresh()->hostname);
        $this->assertSame('Keep notes', $existing->fresh()->description);
        $this->assertModelExists($record);
        $this->assertDatabaseMissing('ip_addresses', ['address' => ip2long('10.0.0.20')]);
        $this->get(route('companies.sites.devices.show', [$this->company, $this->site, $device]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('snipeIt.snapshot.network.ip', '10.0.0.11')
            ->where('snipeIt.snapshot.network.mac', 'AA:BB:CC:DD:EE:01'));
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_ethernet_conflicting_claims_do_not_assign_the_first_asset_or_overwrite_local_data(): void
    {
        $this->activate();
        $subnet = $this->site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $fields = ['IP Address [eth]' => ['value' => '10.0.0.10'], 'MAC Address [eth]' => ['value' => 'AA:BB:CC:DD:EE:01']];
        $this->server([$this->asset(1, ['custom_fields' => $fields]), $this->asset(2, ['custom_fields' => $fields])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->confirm('devices', [['id' => SnipeItAsset::where('external_id', 2)->sole()->id, 'action' => 'create', 'site_id' => $this->site->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ip_addresses', 0);
        $asset = SnipeItAsset::where('external_id', 1)->sole();
        $this->assertNotNull($asset->problem);

        $otherDevice = SnipeItAsset::where('external_id', 2)->sole()->device;
        $record = $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.10'), 'device_id' => $otherDevice->id,
            'mac_address' => 'AA:BB:CC:DD:EE:02', 'description' => 'Keep assignment']);
        $this->server([$this->asset(1, ['custom_fields' => $fields]), $this->asset(2)]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame($otherDevice->id, $record->fresh()->device_id);
        $this->assertSame('AA:BB:CC:DD:EE:02', $record->fresh()->mac_address);
        $this->assertSame('Keep assignment', $record->fresh()->description);
        $this->assertNotNull($asset->fresh()->problem);

        $record->update(['device_id' => null]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertNull($record->fresh()->device_id);
        $this->assertSame('AA:BB:CC:DD:EE:02', $record->fresh()->mac_address);
        $this->assertNotNull($asset->fresh()->problem);
    }

    public function test_ethernet_does_not_create_subnets_or_document_invalid_addresses(): void
    {
        $this->activate();
        $this->site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $this->server([
            $this->asset(1, ['custom_fields' => ['IP Address [eth]' => ['value' => '10.1.0.10']]]),
            $this->asset(2, ['custom_fields' => ['IP Address [eth]' => ['value' => '10.0.0.255']]]),
            $this->asset(3, ['custom_fields' => ['IP Address [eth]' => ['value' => '10.0.0.12'], 'MAC Address [eth]' => ['value' => 'invalid']]]),
        ]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subnets', 1);
        $this->confirm('devices', SnipeItAsset::whereNull('device_id')->get()->map(fn ($asset) => [
            'id' => $asset->id, 'action' => 'create', 'site_id' => $this->site->id,
        ])->all())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ip_addresses', 0);
        $this->assertSame(3, SnipeItAsset::whereNotNull('problem')->count());

        $subnet = $this->site->subnets()->create(Ipv4Cidr::parse('10.1.0.0/24'));
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $asset = SnipeItAsset::where('external_id', 1)->sole();
        $this->assertSame($asset->device_id, $subnet->ipAddresses()->sole()->device_id);
        $this->assertNull($asset->problem);
    }

    public function test_configurable_fields_use_stable_ids_and_preserve_manual_notes(): void
    {
        $this->activate();
        $subnet = $this->site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $fields = [
            ['name' => 'IP Ethernet', 'db_column_name' => '_snipeit_ip_12'],
            ['name' => 'MAC Ethernet', 'db_column_name' => '_snipeit_mac_13'],
            ['name' => 'Nome inventario', 'db_column_name' => '_snipeit_name_14'],
            ['name' => 'Descrizione', 'db_column_name' => '_snipeit_description_15'],
        ];
        $custom = [
            'IP rinominato' => ['field' => '_snipeit_ip_12', 'value' => '10.0.0.12'],
            'MAC rinominato' => ['field' => '_snipeit_mac_13', 'value' => 'AA:BB:CC:DD:EE:12'],
            'Nome rinominato' => ['field' => '_snipeit_name_14', 'value' => 'Workstation'],
            'Descrizione rinominata' => ['field' => '_snipeit_description_15', 'value' => 'Imported notes'],
        ];
        $this->server([$this->asset(1, ['custom_fields' => $custom])], fields: $fields);
        $global = SnipeItSetting::findOrFail('global');
        $revisions = ['revision' => $global->revision, 'global_revision' => $global->revision];
        $this->post(route('administration.integrations.snipe-it.fields'), $revisions)
            ->assertOk()->assertJsonPath('fields.4.value', 'custom:_snipeit_ip_12');
        $mapping = ['ip' => 'custom:_snipeit_ip_12', 'mac' => 'custom:_snipeit_mac_13', 'name' => 'custom:_snipeit_name_14', 'description' => 'custom:_snipeit_description_15'];
        $this->put(route('administration.integrations.snipe-it.map-fields'), [...$revisions,
            'field_mapping' => [...$mapping, 'ip' => 'custom:_snipeit_unavailable_99']])->assertSessionHasErrors('field_mapping.ip');
        $this->assertNull($this->connection->fresh()->field_mapping);
        $oldSync = SnipeItSync::sole();
        $this->put(route('administration.integrations.snipe-it.map-fields'), [...$revisions, 'field_mapping' => $mapping])->assertSessionHasNoErrors();
        $this->post($this->url('confirm'), ['sync_id' => $oldSync->id, 'revision' => $oldSync->revision,
            'view' => 'devices', 'activate' => false, 'decisions' => []])->assertSessionHasErrors('sync');
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $device = Device::sole();
        $this->assertSame('Workstation', $device->name);
        $this->assertSame('Imported notes', $device->notes);
        $this->assertSame(ip2long('10.0.0.12'), $subnet->ipAddresses()->sole()->address);
        $this->assertSame('AA:BB:CC:DD:EE:12', $subnet->ipAddresses()->sole()->mac_address);

        $this->travel(1)->minutes();
        $custom['Descrizione rinominata']['value'] = 'Updated notes';
        $this->server([$this->asset(1, ['custom_fields' => $custom])], fields: $fields);
        $this->post($this->url('sync'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated notes', $device->fresh()->notes);
        $device->update(['notes' => 'Local notes']);
        $custom['Descrizione rinominata']['value'] = 'Changed upstream again';
        $this->server([$this->asset(1, ['custom_fields' => $custom])], fields: $fields);
        $this->post($this->url('sync'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Local notes', $device->fresh()->notes);
        $this->assertNotNull(SnipeItAsset::sole()->problem);
    }

    public function test_legacy_pending_assets_remain_unimported_until_selected(): void
    {
        $this->activate();
        $first = SnipeItAsset::sole();
        $snapshot = [...$first->snapshot, 'name' => 'Device 2', 'asset_tag' => 'TAG-2', 'serial' => 'SERIAL-2'];
        $legacy = SnipeItAsset::create(['sync_id' => $first->sync_id, 'external_id' => 2,
            'snipe_it_model_id' => $first->snipe_it_model_id, 'snapshot' => $snapshot,
            'state' => 'pending', 'needs_confirmation' => true]);
        $this->server([$this->asset(1), $this->asset(2)]);

        $this->post($this->url('sync'))->assertSessionHasNoErrors();

        $this->assertNull($legacy->fresh()->device_id);
        $this->assertTrue($legacy->fresh()->needs_confirmation);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_sync_preserves_detachments_deletions_and_exclusions(): void
    {
        $this->server([$this->asset(1), $this->asset(2), $this->asset(3)]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->confirm('devices', SnipeItAsset::all()->map(fn ($asset) => [
            'id' => $asset->id, 'action' => 'create', 'site_id' => $this->site->id,
        ])->all())->assertSessionHasNoErrors();
        $assets = SnipeItAsset::all()->keyBy('external_id');
        $this->post($this->url('detach'), ['asset_id' => $assets[1]->id, 'confirmed' => true, 'exclude' => false])->assertSessionHasNoErrors();
        $assets[1]->device->delete();
        $assets[2]->device->delete();
        $this->post($this->url('detach'), ['asset_id' => $assets[3]->id, 'confirmed' => true, 'exclude' => true])->assertSessionHasNoErrors();

        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->post($this->url('sync'))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('devices', 1);
        $this->assertSame('detached', $assets[1]->fresh()->state);
        $this->assertSame('detached', $assets[2]->fresh()->state);
        $this->assertSame('excluded', $assets[3]->fresh()->state);
        $this->confirm('excluded', [['id' => $assets[3]->id, 'action' => 'restore']])->assertSessionHasNoErrors();
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertNull($assets[3]->fresh()->device_id);
        $this->confirm('review', [['id' => $assets[2]->id, 'action' => 'create', 'site_id' => $this->site->id]])->assertSessionHasNoErrors();
        $this->assertNotNull($assets[2]->fresh()->device_id);
    }

    public function test_sync_summary_and_filtered_lists_include_linked_network_problems(): void
    {
        $this->server([
            $this->asset(1),
            $this->asset(2, ['custom_fields' => ['IP Address [eth]' => ['value' => '10.0.0.10']]]),
            $this->asset(3, ['model' => null]),
        ]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->confirm('devices', SnipeItAsset::whereIn('external_id', [1, 2])->get()->map(fn ($asset) => [
            'id' => $asset->id, 'action' => 'create', 'site_id' => $this->site->id,
        ])->all())->assertSessionHasNoErrors();

        $this->get($this->url('edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sync.counts.linked', 2)->where('sync.counts.pending', 1)->where('sync.counts.excluded', 0)
            ->where('sync.counts.problems_by_view.linked', 1));
        $this->get($this->url('records').'?view=linked&problem=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)->where('rows.data.0.snapshot.name', 'Device 2'));
        $this->get($this->url('records'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)->where('rows.data.0.snapshot.name', 'Device 3'));
        $this->get($this->url('records').'?view=linked&q=10.0.0.10&sort=ip&direction=asc')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)->where('rows.data.0.snapshot.network.ip', '10.0.0.10'));
    }

    public function test_clients_document_only_ipam_retry_missing_subnets_and_preserve_manual_data(): void
    {
        $fields = ['IP Address [eth]' => ['value' => '10.0.0.10'], 'MAC Address [eth]' => ['value' => 'AA:BB:CC:DD:EE:01']];
        $this->server([$this->asset(1, ['model' => null, 'custom_fields' => $fields])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ip_addresses', 0);
        $this->assertNotNull(SnipeItAsset::sole()->problem);
        $subnet = $this->site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $ip = $subnet->ipAddresses()->sole();
        $this->assertNull($ip->device_id);
        $this->assertSame('Device 1', $ip->hostname);
        $this->assertSame('AA:BB:CC:DD:EE:01', $ip->mac_address);
        $this->assertSame($ip->id, SnipeItAsset::sole()->ip_address_id);
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('device_models', 0);
        $this->assertDatabaseCount('manufacturers', 0);
        $this->get($this->url('edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sync.counts.ipam', 1)->where('sync.counts.linked', 0)->where('sync.counts.pending', 0));
        $this->get(route('companies.sites.subnets.show', [$this->company, $this->site, $subnet, 'search' => 'Device 1']))
            ->assertOk()->assertDontSee('test-key')->assertInertia(fn (Assert $page) => $page
            ->where('ipAddresses.total', 1)->where('ipAddresses.data.0.snipe_it.url', $this->connection->server_url.'/hardware/1'));

        $fields['MAC Address [eth]']['value'] = 'AA:BB:CC:DD:EE:02';
        $this->server([$this->asset(1, ['name' => 'Renamed client', 'custom_fields' => $fields])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame('Renamed client', $ip->fresh()->hostname);
        $this->assertSame('AA:BB:CC:DD:EE:02', $ip->fresh()->mac_address);
        $ip->update(['hostname' => 'Manual name', 'mac_address' => 'AA:BB:CC:DD:EE:03', 'description' => 'Keep notes']);
        $this->server([$this->asset(1, ['name' => 'Changed upstream', 'custom_fields' => $fields])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame('Manual name', $ip->fresh()->hostname);
        $this->assertSame('AA:BB:CC:DD:EE:03', $ip->fresh()->mac_address);
        $this->assertSame('Keep notes', $ip->fresh()->description);
        $this->assertNotNull(SnipeItAsset::sole()->problem);
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_client_subnet_matching_is_company_scoped_and_requires_a_unique_site(): void
    {
        $foreign = Company::create(['name' => 'Beta'])->sites()->create(['name' => 'Foreign']);
        $foreignSubnet = $foreign->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $local = $this->site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $other = $this->company->sites()->create(['name' => 'Milano'])->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $this->server([$this->asset(1, ['custom_fields' => ['IP Address [eth]' => ['value' => '10.0.0.10']]])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ip_addresses', 0);
        $this->assertNotNull(SnipeItAsset::sole()->problem);
        $other->delete();
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertSame($local->id, SnipeItAsset::sole()->ipAddress->subnet_id);
        $this->assertSame(0, $foreignSubnet->ipAddresses()->count());
        $this->confirm('devices', [['id' => SnipeItAsset::sole()->id, 'action' => 'exclude']])->assertSessionHasNoErrors();
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('ip_addresses', 1);
        $this->assertFalse(SnipeItAsset::sole()->ipamData()['active']);
    }

    public function test_client_with_mikrotik_discrepancy_is_visible_without_overwriting_the_observation(): void
    {
        $this->activate();
        $router = Device::sole();
        $subnet = $this->site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $source = $router->mikrotikSources()->make([
            'generation' => (string) Str::uuid(), 'kind' => 'observation', 'source_key' => 'arp:1',
            'state' => 'current', 'seen_at' => now(), 'anchor' => [], 'address' => ip2long('10.0.0.20'),
            'data' => ['menu' => '/ip/arp', 'mac' => 'AA:BB:CC:DD:EE:FF'],
        ]);
        $source->site_id = $this->site->id;
        $source->save();
        $fields = ['IP Address [eth]' => ['value' => '10.0.0.20'], 'MAC Address [eth]' => ['value' => 'AA:BB:CC:DD:EE:02']];
        $this->server([$this->asset(1), $this->asset(2, ['name' => 'Client', 'custom_fields' => $fields])]);
        $this->post($this->url('sync'))->assertSessionHasNoErrors();
        $ip = $subnet->ipAddresses()->sole();
        $asset = SnipeItAsset::where('external_id', 2)->sole();
        $this->assertSame('Client', $ip->hostname);
        $this->assertNull($ip->device_id);
        $this->assertSame('AA:BB:CC:DD:EE:02', $ip->mac_address);
        $this->assertSame($ip->id, $asset->ip_address_id);
        $this->assertStringContainsString('MikroTik', $asset->problem);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $source->fresh()->data['mac']);
        $this->assertSame('AA:BB:CC:DD:EE:02', $asset->ipamData()['mac']);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_scheduled_sync_waits_for_the_configured_interval(): void
    {
        $this->activate();
        $sync = SnipeItSync::current($this->company);
        $this->put(route('administration.integrations.snipe-it.interval'), ['sync_interval' => 45])->assertSessionHasErrors('sync_interval');
        $this->put(route('administration.integrations.snipe-it.interval'), ['sync_interval' => 120])->assertSessionHasNoErrors();

        $sync->update(['synced_at' => now()->subHour()]);
        $this->artisan('snipe-it:sync')->assertSuccessful();
        $this->assertTrue($sync->fresh()->synced_at->lt(now()->subMinutes(50)));

        $sync->update(['synced_at' => now()->subHours(2)]);
        $this->artisan('snipe-it:sync')->assertSuccessful();
        $this->assertTrue($sync->fresh()->synced_at->gt(now()->subMinute()));
    }

    public function test_company_on_the_global_connection_edits_the_shared_field_mapping(): void
    {
        $this->server([], fields: [['db_column_name' => '_snipeit_ip_address_wlan_3', 'name' => 'IP Address [wlan]']]);
        $global = SnipeItSetting::findOrFail('global');
        $setting = SnipeItSetting::forCompany($this->company);
        $mapping = ['ip' => 'custom:_snipeit_ip_address_wlan_3', 'mac' => null, 'name' => 'asset_tag', 'description' => 'notes'];

        $this->put($this->url('map-fields'), [
            'revision' => $setting->revision, 'global_revision' => $global->revision, 'field_mapping' => $mapping,
        ])->assertSessionHasNoErrors()->assertRedirect($this->url('mapping'));

        $this->assertEquals($mapping, $this->connection->fresh()->mapping());
        // Open pages of every company on the global connection must reload before overwriting it.
        $this->assertSame($global->revision + 1, $global->fresh()->revision);
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
            'model' => ['id' => 1, 'name' => 'Model 1'], 'manufacturer' => ['id' => 1, 'name' => 'Vendor'], 'category' => null, 'custom_fields' => [], ...$changes];
    }

    private function modelPayload(int $id, ?string $name = null): array
    {
        // Shape verified against GET /models/{id}; values are synthetic.
        return ['id' => $id, 'name' => $name ?? 'Model '.$id, 'manufacturer' => ['id' => 1, 'name' => 'Vendor'], 'category' => null];
    }

    private function server(array $assets, int $pageSize = 100, ?string $modelName = null, array $fields = []): void
    {
        if ($modelName !== null) {
            $assets = array_map(function ($asset) use ($modelName) {
                $asset['model']['name'] = $modelName;

                return $asset;
            }, $assets);
        }
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            $this->connection->server_url.'/api/v1/fields*' => Http::response(['total' => count($fields), 'rows' => $fields]),
            $this->connection->server_url.'/api/v1/hardware*' => fn (Request $request) => Http::response(['total' => count($assets), 'rows' => array_slice($assets, (int) $request['offset'], $pageSize)]),
            $this->connection->server_url.'/api/v1/models/*' => fn (Request $request) => Http::response($this->modelPayload((int) basename(parse_url($request->url(), PHP_URL_PATH)), $modelName)),
        ]);
    }
}
