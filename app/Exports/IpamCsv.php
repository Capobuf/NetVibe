<?php

namespace App\Exports;

use App\Support\IpamExport;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/** CSV has no sheets or styling: the same rows as the workbook, flattened with their context columns. */
class IpamCsv extends StringValueBinder implements Export, FromArray, WithCustomCsvSettings, WithCustomValueBinder
{
    public function __construct(private IpamExport $export) {}

    public function array(): array
    {
        // Spreadsheet apps would run a cell starting with these characters as a formula.
        return array_map(fn ($row) => array_map(
            fn ($value) => preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value, $row,
        ), $this->export->csvRows());
    }

    public function getCsvSettings(): array
    {
        // Semicolon and BOM let Excel with Italian regional settings open the UTF-8 file directly.
        return ['delimiter' => ';', 'use_bom' => true, 'line_ending' => "\r\n"];
    }
}
