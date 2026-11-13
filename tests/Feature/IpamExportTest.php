<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\MikrotikSource;
use App\Models\Site;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class IpamExportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $torino;

    private Site $roma;

    private Site $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Alfa']);
        $this->torino = $this->company->sites()->create(['name' => 'Torino']);
        $this->roma = $this->company->sites()->create(['name' => 'Roma']);
        $offices = $this->torino->vlans()->create(['tag' => 10, 'name' => 'Uffici']);
        $this->torino->vlans()->create(['tag' => 20]);
        $hidden = $this->torino->vlans()->create(['tag' => 30]);
        $hidden->forceFill(['excluded_at' => now()])->save();
        $lan = $this->torino->subnets()->create([...Ipv4Cidr::parse('192.168.1.0/24'), 'vlan_id' => $offices->id, 'name' => 'LAN']);
        $this->torino->subnets()->create([...Ipv4Cidr::parse('192.168.2.0/24'), 'vlan_id' => $offices->id]);
        $this->torino->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $this->torino->subnets()->create([...Ipv4Cidr::parse('10.30.0.0/24'), 'vlan_id' => $hidden->id]);
        $ip = $lan->ipAddresses()->create(['address' => ip2long('192.168.1.10'), 'hostname' => '=HYPERLINK("x")', 'description' => "Stampante; piano 1\nsala riunioni"]);
        $ip->tags()->attach($this->company->tags()->create(['name' => 'Stampanti']));
        // A current ARP observation occupies .50 without documenting it.
        $device = $this->torino->devices()->create(['name' => 'Router', 'manufacturer_id' => Manufacturer::create(['name' => 'MikroTik'])->id]);
        (new MikrotikSource)->forceFill([
            'site_id' => $this->torino->id, 'device_id' => $device->id, 'generation' => (string) Str::uuid(), 'kind' => 'observation',
            'source_key' => 'arp-50', 'data' => ['menu' => '/ip/arp', 'mac' => 'aa:bb:cc:dd:ee:50'], 'anchor' => [],
            'seen_at' => now(), 'present_at' => now(), 'address' => ip2long('192.168.1.50'),
        ])->save();

        $romaVlan = $this->roma->vlans()->create(['tag' => 10]);
        $this->roma->subnets()->create([...Ipv4Cidr::parse('192.168.1.0/24'), 'vlan_id' => $romaVlan->id])
            ->ipAddresses()->create(['address' => ip2long('192.168.1.20'), 'hostname' => 'roma-nas']);

        $this->foreign = Company::create(['name' => 'Beta'])->sites()->create(['name' => null]);
        $this->foreign->subnets()->create(Ipv4Cidr::parse('192.168.1.0/24'))
            ->ipAddresses()->create(['address' => ip2long('192.168.1.30'), 'hostname' => 'beta-secret']);
    }

    public function test_workbook_has_a_summary_and_one_sheet_per_vlan_of_the_authorized_scope(): void
    {
        $member = User::factory()->create(['is_superadmin' => false]);
        $this->company->users()->attach($member);
        $this->actingAs($member);
        $this->get(route('companies.sites.ipam.download', [$this->foreign->company_id, $this->foreign, 'format' => 'xlsx']))->assertForbidden();

        $book = $this->workbook(['scope' => 'company']);
        $this->assertSame(['Riepilogo', 'Torino · VLAN 10', 'Torino · VLAN 20', 'Torino · Senza VLAN', 'Roma · VLAN 10'], $book->getSheetNames());
        $summary = $this->text($book->getSheetByName('Riepilogo'));
        foreach (['192.168.1.0/24', '192.168.2.0/24', '10.0.0.0/24', 'Senza VLAN', 'Roma'] as $value) {
            $this->assertStringContainsString($value, $summary);
        }

        // One VLAN sheet keeps both subnets under their own heading; an empty one says so instead of a table.
        $vlan = $book->getSheetByName('Torino · VLAN 10');
        $this->assertCount(1, $vlan->getTableCollection());
        $this->assertMatchesRegularExpression('~192\.168\.2\.0/24 IP utilizzabili: 254 .* Nessun IP documentato o rilevato\.$~', $this->text($vlan));
        $rows = collect($vlan->toArray(null, false, false))->keyBy(0);
        $this->assertSame('LAN · 192.168.1.0/24', $vlan->getCell('A5')->getValue());
        $this->assertSame(['Documentato', '=HYPERLINK("x")'], array_slice($rows['192.168.1.10'], 1, 2));
        $this->assertSame('Stampanti', $rows['192.168.1.10'][7]);
        $this->assertSame(['Rilevato', null, 'AA:BB:CC:DD:EE:50', null, null, null, null, 'MikroTik'], array_slice($rows['192.168.1.50'], 1));
        $hostname = $vlan->getCell('C'.($rows->keys()->search('192.168.1.10') + 1));
        $this->assertSame(DataType::TYPE_STRING, $hostname->getDataType());
        $this->assertStringContainsString('roma-nas', $this->text($book->getSheetByName('Roma · VLAN 10')));
        $this->assertStringNotContainsString('beta-secret', implode(array_map(fn ($sheet) => $this->text($sheet), $book->getAllSheets())));

        $this->assertSame(['Riepilogo', 'VLAN 10', 'VLAN 20', 'VLAN 30', 'Senza VLAN'], $this->workbook(['excluded' => 1])->getSheetNames());
    }

    public function test_csv_is_one_flat_table_with_context_columns_and_neutralised_formulas(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));

        $lines = $this->csv(route('companies.sites.ipam.download', [$this->company, $this->torino, 'format' => 'csv', 'scope' => 'company']));
        $this->assertSame(['Azienda', 'Sede', 'VLAN', 'Nome VLAN', 'Subnet', 'CIDR', 'IP', 'Tipo'], array_slice($lines[0], 0, 8));
        $byIp = collect($lines)->keyBy(6);
        $this->assertSame(['Alfa', 'Torino', '10', 'Uffici', 'LAN', '192.168.1.0/24'], array_slice($byIp['192.168.1.10'], 0, 6));
        $this->assertSame("'=HYPERLINK(\"x\")", $byIp['192.168.1.10'][8]);
        $this->assertSame("Stampante; piano 1\nsala riunioni", $byIp['192.168.1.10'][11]);
        $this->assertSame(['Roma', '10'], array_slice($byIp['192.168.1.20'], 1, 2));
        $this->assertContains(['Alfa', 'Torino', '20', '', '', '', ...array_fill(0, 9, '')], $lines);
        $this->assertContains(['Alfa', 'Torino', 'Senza VLAN', '', '', '10.0.0.0/24', ...array_fill(0, 9, '')], $lines);
        $this->assertNotContains('192.168.1.30', $byIp->keys());

        $subnet = $this->torino->subnets()->where('cidr', '192.168.1.0/24')->sole();
        $lines = $this->csv(route('companies.sites.subnets.download', [$this->company, $this->torino, $subnet, 'format' => 'csv']));
        $this->assertSame(['IP', 'Tipo', 'Hostname', 'MAC Address', 'Dispositivo / Interfaccia', 'Descrizione', 'Stato', 'Tag', 'Fonti'], $lines[0]);
        $this->assertSame(['192.168.1.10', '192.168.1.50'], array_column(array_slice($lines, 1), 0));
    }

    private function workbook(array $query): Spreadsheet
    {
        $response = $this->get(route('companies.sites.ipam.download', [$this->company, $this->torino, 'format' => 'xlsx', ...$query]))->assertOk();

        return IOFactory::load($response->baseResponse->getFile()->getPathname());
    }

    /** @return list<list<string>> */
    private function csv(string $url): array
    {
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith("\u{FEFF}", $content);
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, substr($content, 3));
        rewind($handle);
        $lines = [];
        while (($line = fgetcsv($handle, null, ';', '"', '')) !== false) {
            $lines[] = $line;
        }

        return $lines;
    }

    private function text($sheet): string
    {
        return collect($sheet->toArray(null, false, false))->flatten()->filter()->implode(' ');
    }
}
