<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MikrotikSource;
use Tests\TestCase;

class MikrotikSourceDisplayTest extends TestCase
{
    public function test_saved_address_flags_explain_the_actual_import_block_without_changing_the_source(): void
    {
        $problem = 'Indirizzo dinamico, disabilitato, invalido o con flag incompleti: conservato come dato esterno.';
        $source = new MikrotikSource([
            'kind' => 'subnet', 'state' => 'current', 'problem' => $problem,
            'data' => ['active' => false, 'dynamic' => 'false', 'disabled' => 'true', 'invalid' => 'false'],
        ]);
        $source->setRelation('device', (new Device(['name' => 'Router']))->setRelation('mikrotikConnection', null));

        $display = $source->display();

        $this->assertNull($display['problem']);
        $this->assertSame('Indirizzo disabilitato: non importato nell’IPAM.', $display['import_note']);
        $this->assertSame($problem, $source->problem);

        $source->data = ['active' => false, 'dynamic' => true, 'disabled' => false, 'invalid' => false];
        $this->assertNull($source->display()['problem']);
        $this->assertSame('Indirizzo dinamico: non importato nell’IPAM.', $source->display()['import_note']);

        $source->data = ['active' => false, 'dynamic' => false, 'disabled' => false];
        $this->assertSame('Importazione automatica non eseguita: RouterOS non ha indicato se l’indirizzo è non valido.', $source->display()['problem']);

        $source->problem = 'Stesso ID VLAN in contesti L2 non dimostrati equivalenti.';
        $this->assertSame($source->problem, $source->display()['problem']);
    }
}
