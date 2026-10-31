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
}
