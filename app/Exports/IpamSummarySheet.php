<?php

namespace App\Exports;

use App\Support\IpamExport;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** «Riepilogo»: one row per VLAN/subnet pair, empty VLANs and «Senza VLAN» subnets included. */
class IpamSummarySheet extends IpamSheet
{
    /** @param list<string> $sheets Sheet title of each export group, in the same order. */
    public function __construct(IpamExport $export, private array $sheets)
    {
        parent::__construct($export, 'Riepilogo', 0);
    }

    protected function render(Worksheet $sheet): void
    {
        $site = $this->export->multiSite();
        $excluded = $this->export->includeExcluded;
        $headings = [
            ...($site ? ['Sede'] : []), 'VLAN', 'Nome VLAN', 'Nome subnet', 'CIDR',
            'IP utilizzabili', 'Documentati', 'Fonti esterne', 'Riservati', 'Proponibili', ...($excluded ? ['Esclusa'] : []),
        ];
        $rows = [];
        foreach ($this->export->groups() as $index => ['site' => $group, 'vlan' => $vlan, 'subnets' => $subnets]) {
            foreach ($subnets->isEmpty() ? [null] : $subnets as $subnet) {
                $counts = $subnet ? $this->export->counts($subnet) : null;
                $rows[] = [
                    'sheet' => $this->sheets[$index],
                    'values' => [
                        ...($site ? [$this->export->siteName($group)] : []),
                        $vlan ? $vlan->tag : 'Senza VLAN', $vlan?->name, $subnet?->name, $subnet?->cidr,
                        ...($counts ? [$counts['usable'], $counts['documented'], $counts['external'], $counts['excluded'], $counts['proposable']] : array_fill(0, 5, null)),
                        ...($excluded ? [$this->export->isExcluded($vlan, $subnet) ? 'Sì' : null] : []),
                    ],
                ];
            }
        }

        $start = $this->heading($sheet, 'Riepilogo IPAM', $this->export->context());
        if ($rows === []) {
            $this->note($sheet, $start, 'Nessuna VLAN o subnet.');

            return;
        }
        $this->table($sheet, $start, $headings, array_column($rows, 'values'));
        $vlanColumn = $site ? 2 : 1;
        foreach ($rows as $offset => $row) {
            // The VLAN cell opens the sheet that details it.
            $cell = $sheet->getCell([$vlanColumn, $start + 1 + $offset]);
            $cell->getHyperlink()->setUrl(self::link($row['sheet']))->setTooltip('Apri il foglio '.$row['sheet']);
        }
        $first = $vlanColumn + 4;
        $sheet->getStyle([$first, $start + 1, $first + 4, $start + max(1, count($rows))])->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle([$vlanColumn, $start + 1, $vlanColumn, $start + max(1, count($rows))])->getAlignment()->setHorizontal('left');
        $this->widths($sheet, [...($site ? [22] : []), 12, 24, 28, 20, 15, 14, 15, 12, 14, ...($excluded ? [10] : [])]);
        $sheet->freezePane('A'.($start + 1));
    }
}
