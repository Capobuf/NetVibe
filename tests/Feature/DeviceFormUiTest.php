<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DeviceFormUiTest extends TestCase
{
    public function test_device_form_has_create_option_for_device_model()
    {
        $file = file_get_contents(app_path('Filament/Clusters/Racks/Resources/Devices/Schemas/DeviceForm.php'));
        $this->assertStringContainsString("createOptionForm(", $file, 'Device form should define createOptionForm for selects');
        $this->assertStringContainsString("TextInput::make('u_size')", $file, 'DeviceModel quick-create should include u_size');
        $this->assertStringContainsString("Select::make('type')", $file, 'DeviceModel quick-create should include type select');
        $this->assertStringContainsString('->createOptionUsing(function (array $data): int', $file, 'DeviceModel quick-create should define createOptionUsing');
        $this->assertStringContainsString("->createOptionModalHeading('Create Hardware Model')", $file, 'DeviceModel quick-create should set modal heading');
    }

    public function test_device_form_has_create_option_for_rack_with_prefill()
    {
        $file = file_get_contents(app_path('Filament/Clusters/Racks/Resources/Devices/Schemas/DeviceForm.php'));
        $this->assertStringContainsString("Select::make('rack_id')", $file, 'Device form must contain rack_id select');
        $this->assertStringContainsString('createOptionForm(function ($get)', $file, 'rack_id should use a closure-based createOptionForm');
        $this->assertStringContainsString('->createOptionUsing(function (array $data): int', $file, 'rack_id should define createOptionUsing to return new ID');
        $this->assertStringContainsString("->default(fn () => \$get('customer_id'))", $file, 'Rack quick-create should try to default customer_id from parent form');
        $this->assertStringContainsString("->default(fn () => \$get('location_filter_id') ?? \$get('location_id'))", $file, 'Rack quick-create should try to default location from parent form');
        $this->assertStringContainsString("->createOptionModalHeading('Create Rack')", $file, 'Rack quick-create should set modal heading');
    }
}
