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
}
