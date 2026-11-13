<?php

namespace App\Exports;

use App\Support\IpamExport;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Shared layout of the workbook: a document-like title block followed by filterable, banded Excel tables. */
abstract class IpamSheet implements Export, WithEvents, WithTitle
{
    private const MAX_ROWS = 1048576;

    private int $tables = 0;

    public function __construct(protected IpamExport $export, private string $title, private int $index) {}

    abstract protected function render(Worksheet $sheet): void;

    public function title(): string
    {
        return $this->title;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $sheet->setShowGridlines(false);
            $sheet->getParent()->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
            $this->render($sheet);
        }];
    }

    /** Title, context and generation time; returns the first free row. */
    protected function heading(Worksheet $sheet, string $title, string $context): int
    {
        $this->text($sheet, 1, 1, $title)->getStyle()->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('1F2937');
        $this->text($sheet, 1, 2, $context)->getStyle()->getFont()->setSize(10)->getColor()->setRGB('4B5563');
        $generated = 'Generato il '.$this->export->generatedAt->copy()->timezone('Europe/Rome')->format('d/m/Y H:i');
        $this->text($sheet, 1, 3, $generated)->getStyle()->getFont()->setSize(10)->getColor()->setRGB('6B7280');

        return 5;
    }

    /**
     * Writes headings and rows as an Excel table, so every section keeps its own filters and banding.
     * Strings are stored explicitly as text: IPs stay untouched and nothing is ever evaluated as a formula.
     *
     * @param  list<string>  $headings
     * @param  list<list<string|int|null>>  $rows
     */
    protected function table(Worksheet $sheet, int $row, array $headings, array $rows): int
    {
        $end = $row + count($rows);
        if ($end > self::MAX_ROWS) {
            throw new \OverflowException('Il foglio «'.$this->title.'» supera il numero massimo di righe di Excel: esporta in CSV o una subnet alla volta.');
        }
        foreach ([$headings, ...$rows] as $offset => $values) {
            foreach (array_values($values) as $column => $value) {
                $cell = $sheet->getCell([$column + 1, $row + $offset]);
                is_int($value) ? $cell->setValueExplicit($value, DataType::TYPE_NUMERIC)
                    : $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
        }
        $range = 'A'.$row.':'.Coordinate::stringFromColumnIndex(count($headings)).$end;
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $table = new Table($range, 'Tabella'.$this->index.'_'.++$this->tables);
        $table->setStyle((new TableStyle(TableStyle::TABLE_STYLE_MEDIUM2))->setShowRowStripes(true));
        $sheet->addTable($table);

        return $end + 1;
    }

    protected function text(Worksheet $sheet, int $column, int $row, string $value): Cell
    {
        return $sheet->getCell([$column, $row])->setValueExplicit($value, DataType::TYPE_STRING);
    }

    protected function note(Worksheet $sheet, int $row, string $value): int
    {
        $this->text($sheet, 1, $row, $value)->getStyle()->getFont()->setItalic(true)->getColor()->setRGB('6B7280');

        return $row + 1;
    }

    /** @param list<int> $widths */
    protected function widths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
    }

    /** Internal link to another sheet of the workbook. */
    protected static function link(string $title): string
    {
        return "sheet://'".str_replace("'", "''", $title)."'!A1";
    }
}
