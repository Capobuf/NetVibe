<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\ModelType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogBulkDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manufacturers_are_deleted_together_only_when_none_has_models_or_devices(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $first = Manufacturer::create(['name' => 'Unused first']);
        $second = Manufacturer::create(['name' => 'Unused second']);
        $withModel = Manufacturer::create(['name' => 'With model']);
        $model = $withModel->models()->create(['name' => 'Switch']);
        $withDevice = Manufacturer::create(['name' => 'With device']);
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $device = $site->devices()->create(['name' => 'Standalone', 'manufacturer_id' => $withDevice->id]);
        $url = route('manufacturers.bulk-destroy');

        foreach ([$withModel, $withDevice] as $associated) {
            $this->delete($url, ['ids' => [$first->id, $associated->id], 'confirmed' => true])->assertSessionHasErrors('confirmed');
            $this->assertModelExists($first);
            $this->assertModelExists($associated);
        }

        $this->delete($url, ['ids' => [$first->id, $second->id], 'confirmed' => true])
            ->assertSessionHasNoErrors()->assertRedirect(route('manufacturers.index'));
        $this->assertModelMissing($first);
        $this->assertModelMissing($second);
        $this->assertModelExists($withModel);
        $this->assertModelExists($withDevice);
        $this->assertModelExists($model);
        $this->assertModelExists($device);
    }

    public function test_types_are_deleted_together_only_when_none_has_models(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $first = ModelType::create(['name' => 'Unused first']);
        $second = ModelType::create(['name' => 'Unused second']);
        $associated = ModelType::create(['name' => 'Associated']);
        $vendor = Manufacturer::create(['name' => 'Vendor']);
        $model = $vendor->models()->create(['name' => 'Switch', 'model_type_id' => $associated->id]);
        $url = route('model-types.bulk-destroy');

        $this->delete($url, ['ids' => [$first->id, $associated->id], 'confirmed' => true])->assertSessionHasErrors('confirmed');
        $this->assertModelExists($first);
        $this->assertModelExists($associated);

        $this->delete($url, ['ids' => [$first->id, $second->id], 'confirmed' => true])
            ->assertSessionHasNoErrors()->assertRedirect(route('device-models.index', ['tab' => 'types']));
        $this->assertModelMissing($first);
        $this->assertModelMissing($second);
        $this->assertModelExists($associated);
        $this->assertModelExists($model);
    }

    public function test_bulk_deletion_requires_superadmin_confirmation_and_a_valid_selection(): void
    {
        $manufacturer = Manufacturer::create(['name' => 'Vendor']);
        $type = ModelType::create(['name' => 'Switch']);
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_superadmin' => true]);

        foreach (['manufacturers' => $manufacturer, 'model-types' => $type] as $resource => $item) {
            $url = route($resource.'.bulk-destroy');
            $data = ['ids' => [$item->id], 'confirmed' => true];
            $this->actingAs($user)->delete($url, $data)->assertForbidden();
            $this->actingAs($admin);
            $this->delete($url, ['ids' => [$item->id]])->assertSessionHasErrors('confirmed');
            $this->delete($url, ['ids' => [], 'confirmed' => true])->assertSessionHasErrors('ids');
            $this->delete($url, ['ids' => [$item->id, 0], 'confirmed' => true])->assertSessionHasErrors('ids.1');
            $this->delete($url, ['ids' => [$item->id, $item->id], 'confirmed' => true])->assertSessionHasErrors('ids.0');
            $this->assertModelExists($item);
        }
    }
}
