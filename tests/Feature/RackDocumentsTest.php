<?php

namespace Tests\Feature;

use App\Models\Cable;
use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RackDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_include_only_mounted_devices_and_direct_cable_peers_without_duplicates(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $user = User::factory()->create();
        $company->users()->attach($user);
        $this->actingAs($user);
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $model = $vendor->models()->create(['name' => 'Switch', 'rack_height_u' => 1, 'panel' => [
            'faces' => ['front' => ['ports' => [['id' => 'p1', 'label' => 'LAN 1']]], 'rear' => ['ports' => []]],
        ]]);
        $devices = collect(['A', 'B', 'C', 'Outside', 'Unrelated'])->map(fn ($name) => $site->devices()->create([
            'name' => $name, 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id,
        ]));
        $rack = $site->racks()->create(['name' => 'Rack A', 'height_u' => 24, 'numbering' => 'bottom-up']);
        $otherRack = $site->racks()->create(['name' => 'Rack B', 'height_u' => 42, 'numbering' => 'top-down']);
        foreach ([$rack, $otherRack, $otherRack] as $index => $mount) {
            $mount->items()->create([
                'key' => str_repeat((string) ($index + 1), 32), 'kind' => 'device', 'device_id' => $devices[$index]->id,
                'shelf_key' => null, 'position' => $index, 'height' => 1, 'width' => 100, 'x' => 0,
            ]);
        }
        $devices[0]->interfaces()->create(['connector_id' => 'p1', 'name' => 'Porta documentata', 'kind' => 'physical']);
        $cables = collect([[0, 1, 'copper'], [1, 0, 'fiber'], [0, 3, 'power'], [1, 2, 'usb'], [3, 4, 'copper']])
            ->map(function ($pair, $index) use ($devices, $site) {
                $cable = Cable::create(['site_id' => $site->id, 'type' => $pair[2], 'color' => '#000000', 'name' => $index === 0 ? 'Uplink' : null]);
                $cable->ends()->createMany([
                    ['side' => 'a', 'device_id' => $devices[$pair[0]]->id, 'connector_id' => 'p'.($index + 1)],
                    ['side' => 'b', 'device_id' => $devices[$pair[1]]->id, 'connector_id' => 'q'.($index + 1)],
                ]);

                return $cable;
            });
        $this->get(route('companies.sites.racks.documentation', [$company, $site, $rack]))
            ->assertInertia(fn (Assert $page) => $page->component('racks/documentation')
                ->has('document.racks', 1)->has('document.devices', 3)->has('document.cables', 3)
                ->where('document.devices.0.name', 'A')->where('document.devices.1.name', 'B')->where('document.devices.2.name', 'Outside')
                ->where('document.devices.1.rack.name', 'Rack B')->where('document.devices.2.rack', null)
                ->where('document.cables.0.name', 'Uplink')->where('document.cables.0.a.port', 'Porta documentata')
                ->where('document.cables.0.b.port', null)->missing('document.devices.0.interfaces')->missing('document.integrations'));
        $siteDocument = $this->get(route('companies.sites.racks.documentation', [$company, $site]))
            ->assertInertia(fn (Assert $page) => $page->has('document.racks', 2)->has('document.devices', 4)->has('document.cables', 4));
        $this->assertSame($cables->take(4)->pluck('id')->all(), collect($siteDocument->inertiaProps('document.cables'))->pluck('id')->all());
        $empty = $site->racks()->create(['name' => 'Empty', 'height_u' => 24, 'numbering' => 'bottom-up']);
        $this->get(route('companies.sites.racks.documentation', [$company, $site, $empty]))
            ->assertInertia(fn (Assert $page) => $page->has('document.racks', 1)->has('document.devices', 0)->has('document.cables', 0));
    }

    public function test_document_routes_enforce_company_access_and_nested_site_and_rack_scope(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => 'Torino']);
        $otherSite = $company->sites()->create(['name' => 'Milano']);
        $beta = Company::create(['name' => 'Beta']);
        $betaSite = $beta->sites()->create(['name' => null]);
        $rack = $site->racks()->create(['name' => 'Rack', 'height_u' => 24, 'numbering' => 'bottom-up']);
        $this->get(route('companies.sites.racks.documentation', [$company, $site]))->assertRedirect(route('login'));
        $user = User::factory()->create();
        $company->users()->attach($user);
        $this->actingAs($user);
        $this->get(route('companies.sites.racks.documentation', [$beta, $betaSite]))->assertForbidden();
        $this->get(route('companies.sites.racks.documentation', [$company, $betaSite]))->assertNotFound();
        $this->get(route('companies.sites.racks.documentation', [$company, $otherSite, $rack]))->assertNotFound();
    }
}
