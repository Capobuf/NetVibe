<?php

namespace Tests\Feature;

use App\Models\Cable;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Manufacturer;
use App\Models\Rack;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RackCablingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private Rack $rack;

    private DeviceModel $model;

    private Device $source;

    private Device $remote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->company = Company::create(['name' => 'Alfa']);
        $this->site = $this->company->sites()->create(['name' => 'Torino']);
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $face = ['ports' => [], 'image' => null, 'order' => [], 'shellState' => ['hidden' => false, 'locked' => false]];
        $panel = ['version' => 3, 'name' => 'Panel', 'shell' => ['x' => 0, 'y' => 0, 'width' => 482.6, 'height' => 44.45, 'radius' => 0, 'border' => 1],
            'faces' => ['front' => $face, 'rear' => $face], 'numbering' => ['next' => 1, 'prefix' => '']];
        foreach (['a' => 'rj45', 'b' => 'usbA', 'c' => 'ac'] as $id => $type) {
            $panel['faces']['front']['ports'][] = ['id' => str_repeat($id, 32), 'type' => $type, 'x' => 50, 'y' => 20, 'rotation' => 0, 'scale' => 1,
                'label' => strtoupper($id), 'labelPosition' => 'bottom', 'poe' => false, 'group' => null, 'opacity' => 1, 'opacityCustomized' => false, 'borderColor' => '#334455', 'hidden' => false, 'locked' => false];
        }
        $this->model = $vendor->models()->create(['name' => 'Switch', 'panel' => $panel, 'rack_height_u' => 1]);
        $this->source = $this->site->devices()->create(['name' => 'SW-CORE', 'manufacturer_id' => $vendor->id, 'device_model_id' => $this->model->id]);
        $this->remote = $this->site->devices()->create(['name' => 'NAS', 'manufacturer_id' => $vendor->id, 'device_model_id' => $this->model->id]);
        $this->rack = $this->site->racks()->create(['name' => 'Rack A', 'height_u' => 24, 'numbering' => 'bottom-up']);
        $this->rack->items()->create(['key' => str_repeat('d', 32), 'kind' => 'device', 'device_id' => $this->source->id, 'position' => 0, 'height' => 1, 'width' => 100, 'x' => 0]);
    }

    private function url(): string
    {
        return route('companies.sites.racks.update', [$this->company, $this->site, $this->rack]);
    }

    private function cable(string $port = 'a', string $type = 'copper'): array
    {
        return ['id' => -1, 'type' => $type, 'color' => '#2563eb', 'purpose' => 'Management',
            'a' => ['device_id' => $this->source->id, 'connector_id' => str_repeat($port, 32)],
            'b' => ['device_id' => $this->remote->id, 'connector_id' => str_repeat($port, 32)]];
    }

    private function payload(array $changes = []): array
    {
        return [...['name' => 'Rack A', 'revision' => $this->rack->fresh()->revision,
            'cabling_revision' => (int) $this->site->fresh()->cabling_revision,
            'cables' => Cable::query()->with('ends')->orderBy('id')->get()->map->workspaceData()->all()], ...$changes];
    }

    public function test_workspace_keeps_cross_rack_inventory_and_cables_without_interface_diagnostics(): void
    {
        $otherRack = $this->site->racks()->create(['name' => 'Rack B', 'height_u' => 24, 'numbering' => 'bottom-up']);
        $otherRack->items()->create(['key' => str_repeat('e', 32), 'kind' => 'device', 'device_id' => $this->remote->id,
            'position' => 0, 'height' => 1, 'width' => 100, 'x' => 0]);
        $outside = $this->site->devices()->create(['name' => 'Outside', 'manufacturer_id' => $this->model->manufacturer_id, 'device_model_id' => $this->model->id]);
        $interface = $this->source->interfaces()->create(['connector_id' => str_repeat('a', 32), 'name' => 'Documented uplink', 'kind' => 'physical']);
        $source = $this->source->mikrotikSources()->make([
            'generation' => (string) Str::uuid(), 'kind' => 'interface', 'source_key' => 'interface:*1',
            'state' => 'current', 'seen_at' => now(), 'anchor' => [],
            'data' => ['name' => 'Observed name', 'default_name' => 'ether1', 'mac_address' => null],
        ]);
        $source->forceFill(['site_id' => $this->site->id, 'device_interface_id' => $interface->id])->save();
        $crossRack = Cable::create(['site_id' => $this->site->id, 'type' => 'copper', 'color' => '#2563eb']);
        $crossRack->ends()->createMany([
            ['side' => 'a', ...$this->cable()['a']], ['side' => 'b', ...$this->cable()['b']],
        ]);
        $unrelated = Cable::create(['site_id' => $this->site->id, 'type' => 'usb', 'color' => '#2563eb']);
        $unrelated->ends()->createMany([
            ['side' => 'a', 'device_id' => $this->remote->id, 'connector_id' => str_repeat('b', 32)],
            ['side' => 'b', 'device_id' => $outside->id, 'connector_id' => str_repeat('b', 32)],
        ]);
        $foreign = $this->company->sites()->create(['name' => 'Foreign']);
        $foreign->devices()->create(['name' => 'Foreign', 'manufacturer_id' => $this->model->manufacturer_id]);

        $url = route('companies.sites.racks.show', [$this->company, $this->site, $this->rack]);
        foreach ([0, 1] as $editing) {
            $this->get($url.'?edit='.$editing)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->has('devices', 3)->has('cables', 2)
                ->where('devices.0.id', $this->remote->id)
                ->where('devices.0.rack_item.rack.id', $otherRack->id)
                ->where('devices.1.id', $outside->id)
                ->where('devices.1.rack_item', null)
                ->where('devices.2.interfaces.0.name', 'Documented uplink')
                ->where('devices.2.interfaces.0.connector_id', str_repeat('a', 32))
                ->missing('devices.2.interfaces.0.mikrotik')
                ->where('devices.2.model.panel', $this->model->panel)
                ->where('cables.0.b.device_id', $this->remote->id)
                ->where('cables.1.b.device_id', $outside->id)
                ->where('rack.revision', 0)->where('cablingRevision', 0));
        }
        $this->assertModelExists($source);
    }

    public function test_workspace_rejects_occupied_ports_and_stale_cabling_without_partially_saving_the_rack(): void
    {
        $stale = $this->payload();
        $this->put($this->url(), $this->payload(['cables' => [$this->cable()]]))->assertSessionHasNoErrors();
        $cable = Cable::query()->with('ends')->sole();
        $saved = $cable->workspaceData();
        $this->assertSame('Management', $saved['purpose']);
        $this->assertSame($this->remote->id, $saved['b']['device_id']);
        $this->assertSame(2, $cable->ends()->count());
        $this->assertSame(0, $this->source->interfaces()->count());

        $this->put($this->url(), $this->payload(['name' => 'Must not save', 'cables' => [$saved, $this->cable()]]))->assertSessionHasErrors('cables');
        $this->assertSame('Rack A', $this->rack->fresh()->name);
        $this->assertSame(1, $this->rack->fresh()->revision);
        $this->assertDatabaseCount('cables', 1);
        $this->assertDatabaseCount('cable_ends', 2);
        $this->put($this->url(), [...$stale, 'revision' => 1, 'name' => 'Stale'])->assertSessionHasErrors('cables');
        $this->assertSame('Rack A', $this->rack->fresh()->name);
        $this->put($this->url(), $this->payload(['cables' => []]))->assertSessionHasErrors('cables');

        $this->put($this->url(), $this->payload(['items' => [], 'removed_keys' => [str_repeat('d', 32)]]))->assertSessionHasNoErrors();
        $this->delete(route('companies.sites.racks.destroy', [$this->company, $this->site, $this->rack]), ['confirmed' => true, 'revision' => 2])->assertSessionHasNoErrors();
        $this->assertModelExists($cable);
        $this->assertModelExists($this->source);
    }

    public function test_layout_and_multiple_draft_cables_are_saved_together_only_when_all_are_valid(): void
    {
        $items = [
            ['key' => str_repeat('d', 32), 'kind' => 'device', 'device_id' => $this->source->id, 'shelf_key' => null,
                'position' => 3, 'height' => 1, 'width' => 100, 'x' => 0],
            ['key' => str_repeat('e', 32), 'kind' => 'device', 'device_id' => $this->remote->id, 'shelf_key' => null,
                'position' => 6, 'height' => 1, 'width' => 100, 'x' => 0],
        ];
        $draft = $this->payload(['items' => $items, 'cables' => [
            $this->cable(), [...$this->cable('b', 'usb'), 'id' => -2],
        ]]);
        $invalid = $draft;
        $invalid['cables'][1]['type'] = 'copper';

        $this->put($this->url(), $invalid)->assertSessionHasErrors('cables');
        $this->assertDatabaseCount('cables', 0);
        $this->assertDatabaseCount('cable_ends', 0);
        $this->assertSame(0, $this->rack->fresh()->revision);
        $this->assertSame(0, (int) $this->site->fresh()->cabling_revision);
        $this->assertSame(0, $this->rack->items()->sole()->position);

        $this->put($this->url(), $draft)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cables', 2);
        $this->assertDatabaseCount('cable_ends', 4);
        $this->assertSame(1, $this->rack->fresh()->revision);
        $this->assertSame(1, (int) $this->site->fresh()->cabling_revision);
        $this->assertSame(3, $this->rack->items()->where('device_id', $this->source->id)->sole()->position);
        $this->assertSame(6, $this->rack->items()->where('device_id', $this->remote->id)->sole()->position);
        $this->assertSame($this->cable()['a'], Cable::query()->where('type', 'copper')->sole()->workspaceData()['a']);
        $this->assertSame($this->cable('b', 'usb')['b'], Cable::query()->where('type', 'usb')->sole()->workspaceData()['b']);
    }

    public function test_terminations_cannot_cross_sites_or_connector_categories_and_model_ports_are_protected(): void
    {
        $foreignSite = $this->company->sites()->create(['name' => 'Milano']);
        $foreign = $foreignSite->devices()->create(['name' => 'Foreign', 'manufacturer_id' => $this->model->manufacturer_id, 'device_model_id' => $this->model->id]);
        $cable = $this->cable();
        $cable['b']['device_id'] = $foreign->id;
        $this->put($this->url(), $this->payload(['cables' => [$cable]]))->assertSessionHasErrors('cables');
        $cable = $this->cable();
        $cable['b']['connector_id'] = str_repeat('c', 32);
        $this->put($this->url(), $this->payload(['cables' => [$cable]]))->assertSessionHasErrors('cables');
        $cable = $this->cable('c', 'power');
        $cable['b']['connector_id'] = str_repeat('a', 32);
        $this->put($this->url(), $this->payload(['cables' => [$cable]]))->assertSessionHasErrors('cables');
        $this->assertDatabaseCount('cables', 0);
        $this->assertDatabaseCount('cable_ends', 0);

        $this->put($this->url(), $this->payload(['cables' => [$this->cable('b', 'usb')]]))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->remote->interfaces()->count());
        $panel = $this->model->panel;
        $panel['faces']['front']['ports'] = array_values(array_filter($panel['faces']['front']['ports'], fn ($port) => $port['type'] !== 'usbA'));
        $this->postJson(route('manufacturers.models.panel.update', [$this->model->manufacturer_id, $this->model]), ['document' => json_encode($panel), 'revision' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('document');
        $panel = $this->model->panel;
        $panel['faces']['front']['ports'][1]['type'] = 'ac';
        $this->postJson(route('manufacturers.models.panel.update', [$this->model->manufacturer_id, $this->model]), ['document' => json_encode($panel), 'revision' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertSame('usbA', $this->model->fresh()->panel['faces']['front']['ports'][1]['type']);

        $this->actingAs(User::factory()->create())->put($this->url(), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('cables', 1);
    }

    public function test_device_deletion_requires_updated_cable_impact_and_removes_both_ends_only_after_confirmation(): void
    {
        $url = route('companies.sites.devices.deletion-impact', [$this->company, $this->site, $this->remote]);
        $old = $this->getJson($url)->assertOk()->json();
        $this->put($this->url(), $this->payload(['cables' => [$this->cable('b', 'usb')]]))->assertSessionHasNoErrors();
        $destroy = route('companies.sites.devices.destroy', [$this->company, $this->site, $this->remote]);
        $this->delete($destroy, ['confirmed' => true, 'impact_token' => $old['impact_token']])->assertSessionHasErrors('impact_token');
        $this->assertModelExists($this->remote);
        $impact = $this->getJson($url)->assertOk()->assertJsonCount(1, 'cables')->json();
        $otherModel = DeviceModel::create(['name' => 'Other', 'manufacturer_id' => $this->model->manufacturer_id]);
        $this->patch(route('companies.sites.devices.update', [$this->company, $this->site, $this->remote]), ['name' => 'NAS', 'device_model_id' => $otherModel->id])->assertSessionHasErrors('device_model_id');
        $this->delete($destroy, ['confirmed' => true, 'impact_token' => $impact['impact_token']])->assertSessionHasNoErrors();
        $this->assertModelMissing($this->remote);
        $this->assertModelExists($this->source);
        $this->assertDatabaseCount('cables', 0);
        $this->assertDatabaseCount('cable_ends', 0);
    }

    public function test_database_prevents_a_port_being_reused_on_the_other_side_of_a_cable(): void
    {
        $this->put($this->url(), $this->payload(['cables' => [$this->cable()]]))->assertSessionHasNoErrors();
        $other = Cable::create(['site_id' => $this->site->id, 'type' => 'copper', 'color' => '#2563eb']);
        $this->expectException(UniqueConstraintViolationException::class);
        $other->ends()->create(['side' => 'b', 'device_id' => $this->source->id, 'connector_id' => str_repeat('a', 32)]);
    }

    public function test_new_connection_commits_only_explicit_disconnections_on_reused_ports_atomically(): void
    {
        $this->put($this->url(), $this->payload(['cables' => [$this->cable('c', 'power'), [...$this->cable('b', 'usb'), 'id' => -2]]]))
            ->assertSessionHasNoErrors();
        $old = Cable::query()->where('type', 'power')->sole();
        $unrelated = Cable::query()->where('type', 'usb')->sole();
        $url = route('companies.sites.racks.cables.store', [$this->company, $this->site, $this->rack]);
        $replacement = [...$this->cable('c', 'power'), 'cabling_revision' => 1,
            'removed_cable_ids' => [$old->id, $unrelated->id]];

        $this->post($url, [...$replacement, 'cabling_revision' => 0])->assertSessionHasErrors('cables');
        $this->post($url, [...$replacement, 'type' => 'copper'])->assertSessionHasErrors('cables');
        $this->assertModelExists($old);
        $this->assertDatabaseCount('cable_ends', 4);
        $this->assertSame(1, (int) $this->site->fresh()->cabling_revision);

        $this->post($url, $replacement)->assertSessionHasNoErrors();
        $this->assertModelMissing($old);
        $this->assertModelExists($unrelated);
        $this->assertSame($this->cable('c', 'power')['a'], Cable::query()->where('type', 'power')->sole()->workspaceData()['a']);
        $this->assertDatabaseCount('cables', 2);
        $this->assertDatabaseCount('cable_ends', 4);
        $this->assertSame(2, (int) $this->site->fresh()->cabling_revision);
        $this->assertSame(1, $this->rack->fresh()->revision);
        $this->assertSame(1, $this->rack->items()->count());
    }

    public function test_table_creates_a_cable_immediately_without_saving_the_rack_layout(): void
    {
        $url = route('companies.sites.racks.cables.store', [$this->company, $this->site, $this->rack]);
        $this->post($url, [...$this->cable(), 'cabling_revision' => 0, 'name' => 'Unsaved draft', 'items' => []])
            ->assertSessionHasNoErrors()->assertRedirect(route('companies.sites.racks.show', [$this->company, $this->site, $this->rack]));
        $this->assertDatabaseCount('cables', 1);
        $this->assertDatabaseCount('cable_ends', 2);
        $this->assertSame('Rack A', $this->rack->fresh()->name);
        $this->assertSame(0, $this->rack->fresh()->revision);
        $this->assertSame(1, $this->rack->items()->count());

        $this->post($url, [...$this->cable('b', 'usb'), 'cabling_revision' => 0])->assertSessionHasErrors('cables');
        $this->post($url, [...$this->cable(), 'cabling_revision' => 1])->assertSessionHasErrors('cables');

        $foreignSite = $this->company->sites()->create(['name' => 'Milano']);
        $foreign = $foreignSite->devices()->create(['name' => 'Foreign', 'manufacturer_id' => $this->model->manufacturer_id, 'device_model_id' => $this->model->id]);
        $cable = $this->cable('b', 'usb');
        $cable['b']['device_id'] = $foreign->id;
        $this->post($url, [...$cable, 'cabling_revision' => 1])->assertSessionHasErrors('cables');
        $outside = $this->site->devices()->create(['name' => 'Outside', 'manufacturer_id' => $this->model->manufacturer_id, 'device_model_id' => $this->model->id]);
        $cable['a']['device_id'] = $this->remote->id;
        $cable['b']['device_id'] = $outside->id;
        $this->post($url, [...$cable, 'cabling_revision' => 1])->assertSessionHasErrors(['cables' => 'Almeno un’estremità deve appartenere al rack aperto.']);
        $this->actingAs(User::factory()->create())->post($url, [...$this->cable(), 'cabling_revision' => 1])->assertForbidden();
        $this->assertDatabaseCount('cables', 1);
        $this->assertDatabaseCount('cable_ends', 2);
    }

    public function test_cable_labels_use_saved_floors_names_and_ports_only_in_the_authorized_site(): void
    {
        $this->assertNull($this->rack->floor);
        $createRack = route('companies.sites.racks.store', [$this->company, $this->site]);
        $this->post($createRack, ['name' => 'Rack B', 'floor' => 'P-2'])->assertSessionHasNoErrors();
        $otherRack = $this->site->racks()->where('name', 'Rack B')->sole();
        $otherRack->items()->create(['key' => str_repeat('e', 32), 'kind' => 'device', 'device_id' => $this->remote->id, 'position' => 0, 'height' => 1, 'width' => 100, 'x' => 0]);
        $this->put($this->url(), $this->payload(['floor' => 'PT', 'cables' => [[...$this->cable(), 'name' => 'Uplink']]]))->assertSessionHasNoErrors();
        $cable = Cable::sole();
        $url = route('companies.sites.racks.cables.labels', [$this->company, $this->site, $this->rack, $cable->id]);
        $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('racks/cable-labels')->where('cable.name', 'Uplink')
            ->where('ends.a.piano', 'PT')->where('ends.b.piano', 'P-2')
            ->where('ends.a.dispositivo', 'SW-CORE')->where('ends.b.porta', 'A'));
        $this->assertSame('Management', $cable->purpose);
        $this->assertSame(1, $this->rack->items()->count());
        $this->put($this->url(), $this->payload(['floor' => '1']))->assertSessionHasErrors('floor');
        $this->assertSame('PT', $this->rack->fresh()->floor);
        $this->put($this->url(), $this->payload(['floor' => 'P1']))->assertSessionHasNoErrors();
        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('ends.a.piano', 'P1'));
        $otherRack->items()->delete();
        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('ends.b.piano', null)->where('ends.b.rack', null));
        $saved = $cable->fresh('ends')->workspaceData();
        $this->put($this->url(), $this->payload(['cables' => [[...$saved, 'name' => null]]]))->assertSessionHasNoErrors();
        $this->assertNull($cable->fresh()->name);
        $this->assertSame('Management', $cable->fresh()->purpose);
        $user = User::factory()->create();
        $this->actingAs($user)->get($url)->assertForbidden();
        $this->company->users()->attach($user);
        $this->get($url)->assertOk();
        $foreignSite = $this->company->sites()->create(['name' => 'Altrove']);
        $foreignCable = Cable::create(['site_id' => $foreignSite->id, 'type' => 'copper', 'color' => '#000000']);
        $this->get(route('companies.sites.racks.cables.labels', [$this->company, $this->site, $this->rack, $foreignCable->id]))->assertNotFound();
        $this->get(route('companies.sites.racks.cables.labels', [$this->company, $foreignSite, $this->rack, $cable->id]))->assertNotFound();
    }
}
