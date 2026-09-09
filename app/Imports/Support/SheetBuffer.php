<?php

namespace App\Imports\Support;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Events\AfterImport;

/**
 * A sheet handler that only collects.
 *
 * An importer spread over several sheets cannot act on any one of them alone —
 * a child sheet means nothing until the sheet it joins to has been read. So
 * each sheet is buffered here and the parent does the work once the whole
 * workbook is in ({@see AfterImport}).
 */
class SheetBuffer implements ToArray, WithHeadingRow
{
    /** @var list<array{line: int, cells: array<string, mixed>}> */
    private array $rows = [];

    private bool $read = false;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function array(array $rows): void
    {
        $this->read = true;

        foreach ($rows as $index => $cells) {
            // +2: the heading row is 1, so the first data row is 2.
            $this->rows[] = ['line' => $index + 2, 'cells' => (array) $cells];
        }
    }

    /**
     * @return list<array{line: int, cells: array<string, mixed>}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Whether the sheet was present in the workbook at all — which is not the
     * same as it having rows, and is the difference between "this list is
     * empty" and "this list was not part of the upload".
     */
    public function wasRead(): bool
    {
        return $this->read;
    }
}
