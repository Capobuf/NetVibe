<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Rack;
use App\Models\Device;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Models\IpAddress;
use App\Models\DeviceModel;
use App\Models\Concerns\TenantAware;

class ArchTest extends TestCase
{
    public function test_tenant_aware_trait_is_applied_to_tenant_models()
    {
        $this->assertContains(TenantAware::class, class_uses(Rack::class));
        $this->assertContains(TenantAware::class, class_uses(Device::class));
        $this->assertContains(TenantAware::class, class_uses(Subnet::class));
        $this->assertContains(TenantAware::class, class_uses(Vlan::class));
        $this->assertContains(TenantAware::class, class_uses(IpAddress::class));
    }

    public function test_device_model_is_not_tenant_aware()
    {
        $this->assertNotContains(TenantAware::class, class_uses(DeviceModel::class));
    }

    public function test_does_not_reference_legacy_filament_forms_layout_components()
    {
        $files = \Illuminate\Support\Facades\File::allFiles(app_path());

        $forbidden = [
            'Filament\\Forms\\Components\\Group',
            'Filament\\Forms\\Components\\Section',
            'Filament\\Forms\\Components\\Grid',
            'Filament\\Forms\\Components\\Split',
            'Filament\\Forms\\Components\\Tabs',
        ];

        foreach ($files as $file) {
            $path = $file->getRealPath();

            if (! str_ends_with($path, '.php')) {
                continue;
            }

            $content = file_get_contents($path);

            foreach ($forbidden as $pattern) {
                if (str_contains($content, $pattern)) {
                    $this->fail("Found forbidden legacy Filament Forms layout usage [{$pattern}] in file: " . $file->getRelativePathname());
                }
            }
        }

        $this->assertTrue(true);
    }

    public function test_app_filament_uses_schemas_group_namespace()
    {
        // Basic positive check: ensure at least one file in app/Filament references
        // Filament\Schemas\Components\Group (so we don't ban the layout in total)
        $files = \Illuminate\Support\Facades\File::allFiles(app_path('Filament'));
        $found = false;

        foreach ($files as $file) {
            $path = $file->getRealPath();
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            $content = file_get_contents($path);
            if (str_contains($content, 'Filament\\Schemas\\Components\\Group') || str_contains($content, 'Filament\\Schemas\\Components\\Section') || str_contains($content, 'Filament\\Schemas\\Components\\Grid')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected at least one file in app/Filament to use Filament\\Schemas\\Components\Group/Section/Grid');
    }
}
