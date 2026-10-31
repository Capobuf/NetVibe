<?php

namespace Tests\Unit;

use App\Support\MikrotikAnalysis;
use PHPUnit\Framework\TestCase;

class MikrotikAnalysisTest extends TestCase
{
    public function test_synthetic_contexts_keep_vlan_collisions_and_unknown_flags_distinct(): void
    {
        $tables = json_decode(file_get_contents(__DIR__.'/../Fixtures/mikrotik/synthetic.json'), true, flags: JSON_THROW_ON_ERROR)['tables'];
        unset($tables['system/identity'], $tables['system/resource']);
        $findings = array_column(MikrotikAnalysis::summarize($tables), null, 'label');

        $this->assertSame(1, $findings['ID VLAN in più contesti']['value']);
        $this->assertSame('VLAN filtering: 1 attivi, 1 disattivi, 1 non determinabili.', $findings['Bridge nel dispositivo']['detail']);
        $this->assertSame('1 statiche, 1 dinamiche, 1 non classificabili.', $findings['Lease DHCP']['detail']);
        $this->assertSame('1 interfacce con più indirizzi.', $findings['IPv4 configurati sul dispositivo']['detail']);
        $cases = array_column(MikrotikAnalysis::cases($tables), 'status', 'label');
        $this->assertSame('Riscontrata', $cases['Più bridge']);
        $this->assertSame('Riscontrata', $cases['VLAN su bridge']);
        $this->assertSame('Riscontrata', $cases['VLAN su interfaccia Ethernet']);
        $this->assertSame('Riscontrata', $cases['Liste in tagged/untagged (RouterOS 7.17+)']);
        $single = array_column(MikrotikAnalysis::cases(['interface/bridge' => [['name' => 'one']]]), 'status', 'label');
        $this->assertSame('Riscontrata', $single['Un solo bridge']);
        $tables['interface/bridge'] = [];
        $tables['interface/bridge/vlan'] = [];
        $tables['interface/vlan'] = [];
        $empty = array_column(MikrotikAnalysis::summarize($tables), null, 'label');
        $this->assertSame(0, $empty['Bridge nel dispositivo']['value']);
        $this->assertSame(0, $empty['ID VLAN in più contesti']['value']);
        unset($tables['interface/bridge']);
        $unavailable = array_column(MikrotikAnalysis::summarize($tables), null, 'label');
        $this->assertArrayNotHasKey('Bridge nel dispositivo', $unavailable);
        $this->assertArrayNotHasKey('ID VLAN in più contesti', $unavailable);
    }
}
