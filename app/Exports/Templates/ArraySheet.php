<?php

namespace App\Exports\Templates;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One sheet of an import template: a title, a heading row, and the rows
 * beneath it.
 *
 * Every template sheet is the same object with different contents, so they
 * share this class rather than each getting one of their own — what varies is
 * data, and it belongs in the export that owns it.
 *
 * Three things it does for readability, since these files are read by people
 * rather than parsers: the heading row is frozen so it stays put while
 * scrolling; columns size themselves unless a width is given, in which case
 * they wrap instead (which is what keeps a sheet of prose from stretching off
 * the screen — the widths are applied after the auto-sizing and switch it off
 * per column, so the two coexist); and named rows can be banded as section
 * headings, which is what gives the guide sheet its structure.
 */
class ArraySheet implements FromArray, ShouldAutoSize, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string>>  $rows
     * @param  array<string, int>  $columnWidths  column letter => width
     * @param  list<int>  $bandedRows  1-based indices into $rows to show as section headings
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $columnWidths = [],
        private readonly array $bandedRows = [],
    ) {}

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        return $this->columnWidths;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        // Keep the heading row visible while the reader scrolls.
        $sheet->freezePane('A2');

        $last = Coordinate::stringFromColumnIndex(max(1, count($this->headings)));

        $styles = ["A1:{$last}1" => ['font' => ['bold' => true]]];

        // A fixed-width column has to wrap, or its text is simply clipped.
        $bottom = count($this->rows) + 1;

        foreach (array_keys($this->columnWidths) as $column) {
            $styles["{$column}1:{$column}{$bottom}"] = [
                'alignment' => ['wrapText' => true, 'vertical' => 'top'],
            ];
        }

        foreach ($this->bandedRows as $index) {
            $row = $index + 1; // the heading row is 1

            $styles["A{$row}:{$last}{$row}"] = [
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE2E8F0']],
            ];
        }

        return $styles;
    }
}
