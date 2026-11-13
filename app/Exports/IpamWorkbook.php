<?php

namespace App\Exports;

use App\Support\IpamExport;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithProperties;

/**
 * Consultation workbook: «Riepilogo» followed by one sheet per VLAN. A single subnet reuses its VLAN sheet.
 * Callers pick the destination (download now, Excel::store()/raw() for a later Drive upload).
 */
class IpamWorkbook implements WithMultipleSheets, WithProperties
{
    public function __construct(private IpamExport $export) {}

    public function sheets(): array
    {
        $used = ['riepilogo' => true];
        $groups = $this->export->groups();
        $titles = array_map(fn ($group) => self::title($group, $this->export, $used), $groups);
        $sheets = array_map(fn ($group, $index) => new IpamVlanSheet($this->export, $titles[$index], $index + 1, $group), $groups, array_keys($groups));

        return $this->export->subnet ? $sheets : [new IpamSummarySheet($this->export, $titles), ...$sheets];
    }

    public function properties(): array
    {
        return ['creator' => 'NetVibe', 'title' => 'IPAM · '.$this->export->context()];
    }

    /**
     * «VLAN 300», prefixed by the site when the same tag can repeat. Excel names are unique regardless
     * of case, at most 31 characters and without : \ / ? * [ ] or edge apostrophes.
     *
     * @param  array<string, true>  $used
     */
    private static function title(array $group, IpamExport $export, array &$used): string
    {
        $base = $group['vlan'] ? 'VLAN '.$group['vlan']->tag : 'Senza VLAN';
        if ($export->multiSite()) {
            $site = str_replace([':', '\\', '/', '?', '*', '[', ']'], '', $export->siteName($group['site']));
            $base = trim(mb_substr($site, 0, 31 - mb_strlen(' · '.$base)), " '").' · '.$base;
        }
        $title = $base;
        for ($copy = 2; isset($used[mb_strtolower($title)]); $copy++) {
            $title = mb_substr($base, 0, 31 - mb_strlen(" ($copy)"))." ($copy)";
        }
        $used[mb_strtolower($title)] = true;

        return $title;
    }
}
