<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FloorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_floor_plans_create_floors_keep_positions_per_site_and_protect_inventory(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $other = Company::create(['name' => 'Beta'])->sites()->create(['name' => null]);
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $model = $vendor->models()->create(['name' => 'AP']);
        $device = $site->devices()->create(['name' => 'AP-01', 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id]);
        $foreign = $other->devices()->create(['name' => 'AP-99', 'manufacturer_id' => $vendor->id, 'device_model_id' => $model->id]);
        $rack = $site->racks()->create(['name' => 'Armadio', 'height_u' => 24, 'numbering' => 'bottom-up']);

        // A floor exists on its own; a PDF upload fills it and creates the missing one.
        $this->post(route('companies.sites.floors.store', [$company, $site]), ['floor' => 'PT'])->assertSessionHasNoErrors();
        $plans = route('companies.sites.floors.plans', [$company, $site]);
        $this->post($plans, ['pages' => [
            ['floor' => 'PT', 'image' => UploadedFile::fake()->image('1.png', 1600, 1000)],
            ['floor' => 'PT', 'image' => UploadedFile::fake()->image('2.png', 1600, 1000)],
        ]])->assertSessionHasErrors('pages.1.floor');
        $this->post($plans, ['pages' => [
            ['floor' => 'PT', 'image' => UploadedFile::fake()->image('1.png', 1600, 1000)],
            ['floor' => 'P1', 'image' => UploadedFile::fake()->image('2.png', 1200, 1200)],
        ]])->assertSessionHasNoErrors();
        $ground = $site->floors()->where('code', 'PT')->sole();
        $this->assertSame(['P1', 'PT'], $site->floors()->orderBy('code')->pluck('code')->all());
        $this->assertSame([1600, 1000], [$ground->image_width, $ground->image_height]);
        Storage::disk('local')->assertExists($ground->image_path);

        // Positions reference only this site's inventory and follow the revision.
        $layout = route('companies.sites.floors.layout', [$company, $site, $ground]);
        $items = [['device_id' => $device->id, 'rack_id' => null, 'x' => 0.25, 'y' => 0.5], ['device_id' => null, 'rack_id' => $rack->id, 'x' => 0.75, 'y' => 0.5]];
        $this->put($layout, ['revision' => $ground->revision, 'items' => [...$items, ['device_id' => $foreign->id, 'rack_id' => null, 'x' => 0.1, 'y' => 0.1]]])->assertSessionHasErrors('items');
        $this->put($layout, ['revision' => $ground->revision, 'items' => $items])->assertSessionHasNoErrors();
        $this->put($layout, ['revision' => $ground->revision, 'items' => []])->assertSessionHasErrors('revision');
        $this->assertSame(2, $ground->items()->count());

        // Another company cannot reach the floor or its image.
        $this->get(route('companies.sites.floors.show', [$other->company, $other, $ground]))->assertNotFound();
        $this->get(route('companies.sites.floors.image', [$other->company, $other, $ground]))->assertNotFound();

        // A plan with other proportions needs an explicit choice for existing positions.
        $this->post($plans, ['pages' => [['floor' => 'PT', 'image' => UploadedFile::fake()->image('3.png', 1000, 1000)]]])->assertSessionHasErrors('positions');
        $this->assertSame(2, $ground->items()->count());
        $previous = $ground->fresh()->image_path;
        $this->post($plans, ['pages' => [['floor' => 'PT', 'image' => UploadedFile::fake()->image('3.png', 1000, 1000)]], 'positions' => 'clear'])->assertSessionHasNoErrors();
        $this->assertSame(0, $ground->items()->count());
        Storage::disk('local')->assertMissing($previous);

        // Removing a floor keeps devices and racks and deletes its image.
        $ground->refresh();
        $this->delete(route('companies.sites.floors.destroy', [$company, $site, $ground]), ['confirmed' => true, 'revision' => $ground->revision])->assertSessionHasNoErrors();
        $this->assertModelMissing($ground);
        $this->assertModelExists($device);
        $this->assertModelExists($rack);
        Storage::disk('local')->assertMissing($ground->image_path);
    }
}
