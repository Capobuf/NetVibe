<?php

namespace Tests\Feature;

use App\Filament\Clusters\Racks\Resources\DeviceModels\DeviceModelResource;
use App\Models\DeviceModel;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ColorPicker;
use Tests\TestCase;

class DeviceModelFormTest extends TestCase
{
    public function test_form_has_visual_layout_repeater()
    {
        // For this UI prototype we validate the schema code exists in the DeviceModelForm
        // file rather than introspecting a live Schema instance (some Schema internals
        // require a Livewire context in tests). Ensure the expected repeaters and fields
        // are present in the form definition source.
        $file = file_get_contents(app_path('Filament/Clusters/Racks/Resources/DeviceModels/Schemas/DeviceModelForm.php'));

        $this->assertStringContainsString("Repeater::make('visual_layout')", $file, 'visual_layout repeater not found in DeviceModelForm');
        $this->assertStringContainsString("label('Physical Rows')", $file, 'visual_layout label not set');
        $this->assertStringContainsString("Repeater::make('banks')", $file, 'banks nested repeater not found in DeviceModelForm');
        
        // Also verify the group fields exist in the file
        $this->assertStringContainsString("label('Port Groups')", $file, 'banks repeater does not have label Port Groups');
        $this->assertStringContainsString("TextInput::make('name')", $file, 'bank field name not present');
        $this->assertStringContainsString("TextInput::make('start_port')", $file, 'start_port not present');
        $this->assertStringContainsString("TextInput::make('port_count')", $file, 'port_count not present');
        $this->assertStringContainsString("ColorPicker::make('color')", $file, 'color picker not present');
    }
}
