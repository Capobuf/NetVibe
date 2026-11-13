<?php

namespace App\Exports;

use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Support\IpamExport;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** One VLAN (or the «Senza VLAN» subnets of a site): every subnet gets its own heading and table. */
class IpamVlanSheet extends IpamSheet
{
    /** @param array{site: Site, vlan: ?Vlan, subnets: Collection<int, Subnet>} $group */
    public function __construct(IpamExport $export, string $title, int $index, private array $group)
    {
        parent::__construct($export, $title, $index);
    }

    protected function render(Worksheet $sheet): void
    {
        ['site' => $site, 'vlan' => $vlan, 'subnets' => $subnets] = $this->group;
        $title = $vlan ? collect(["VLAN {$vlan->tag}", $vlan->name])->filter()->implode(' · ') : 'Senza VLAN';
        $context = collect([$this->export->company->name, $site->name])->filter()->implode(' · ');
        $row = $this->heading($sheet, $title.($vlan?->excluded_at ? ' (esclusa da NetVibe)' : ''), $context);
        $this->widths($sheet, [17, 13, 26, 20, 30, 44, 16, 24, 18]);
        $freeze = 'A4';
        if ($subnets->isEmpty()) {
            $this->note($sheet, $row, 'Nessuna subnet associata a questa VLAN.');
        }
        foreach ($subnets as $subnet) {
            $heading = collect([$subnet->name, $subnet->cidr])->filter()->implode(' · ');
            $style = $this->text($sheet, 1, $row, $heading.($subnet->excluded_at ? ' (esclusa da NetVibe)' : ''))->getStyle();
            $style->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('1E3A8A');
            $sheet->getStyle([1, $row, 9, $row])->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('BFDBFE');
            $counts = $this->export->counts($subnet);
            $summary = collect([
                'IP utilizzabili' => $counts['usable'], 'Documentati' => $counts['documented'], 'Fonti esterne' => $counts['external'],
                'Riservati' => $counts['excluded'], 'Proponibili' => $counts['proposable'],
            ])->map(fn ($count, $label) => $label.': '.number_format($count, 0, ',', '.'))->implode('   ·   ');
            $this->text($sheet, 1, $row + 1, $summary)->getStyle()->getFont()->setSize(10)->getColor()->setRGB('4B5563');
            $row += 2;
            if ($subnet->description) {
                $row = $this->note($sheet, $row, $subnet->description);
            }
            $rows = $this->export->rows($subnet);
            if ($rows === []) {
                $row = $this->note($sheet, $row + 1, 'Nessun IP documentato o rilevato.') + 2;

                continue;
            }
            $start = $row + 1;
            if ($subnets->count() === 1) {
                $freeze = 'A'.($start + 1);
            }
            $end = $this->table($sheet, $start, IpamExport::COLUMNS, $rows);
            $sheet->getStyle([6, $start + 1, 6, $end - 1])->getAlignment()->setWrapText(true);
            $row = $end + 2;
        }
        $sheet->freezePane($freeze);
    }
}
