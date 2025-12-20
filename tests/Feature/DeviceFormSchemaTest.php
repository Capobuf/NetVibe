<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Clusters\Racks\Resources\Devices\Schemas\DeviceForm;
use Filament\Forms\Components\Select;
use Tests\TestCase;

class DeviceFormSchemaTest extends TestCase
{
    /**
     * Verifica che i select chiave esistano e che le quick-create per hardware
     * model e rack siano configurate. Evita controlli su stringhe del file sorgente.
     */
    public function test_device_form_selects_and_quick_create_are_configured(): void
    {
        $components = DeviceForm::schema();

        $selects = [];
        $textInputs = [];
        foreach ($components as $component) {
            if ($component instanceof Select) {
                $selects[$component->getName()] = $component;
            }
            if ($component instanceof \Filament\Forms\Components\TextInput) {
                $textInputs[$component->getName()] = $component;
            }
        }

        // Selettori principali presenti
        $this->assertArrayHasKey('customer_id', $selects);
        $this->assertArrayHasKey('location_filter_id', $selects);
        $this->assertArrayHasKey('rack_id', $selects);
        $this->assertArrayHasKey('device_model_id', $selects);

        // Quick-create per device_model_id
        $deviceModelSelect = $selects['device_model_id'];
        $this->assertTrue($deviceModelSelect->hasCreateOptionActionFormSchema());

        // Quick-create per rack_id
        $rackSelect = $selects['rack_id'];
        $this->assertTrue($rackSelect->hasCreateOptionActionFormSchema());

        // Campi IP: management_ip è stato rimosso per evitare duplicati con ip_addr
        $this->assertArrayHasKey('ip_addr', $textInputs);
        $this->assertArrayNotHasKey('management_ip', $textInputs);
    }
}
