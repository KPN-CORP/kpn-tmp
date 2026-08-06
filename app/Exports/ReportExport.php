<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Exports the HC Report (already-scoped, already-mapped rows) to a spreadsheet.
 *
 * The visible columns depend on the requested report type:
 *   - talent_report → Potential + Talent Box
 *   - idp_progress  → IDP Progress
 */
class ReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows  Mapped report rows.
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly string $reportType,
        private readonly ?string $year,
    ) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        $base = ['Employee ID', 'Employee Name', 'Business Unit', 'Job Level', 'Position', 'Department'];

        return match ($this->reportType) {
            'talent_report' => [...$base, 'Potential', 'Talent Box'],
            'idp_progress' => [...$base, 'IDP Progress'],
            default => $base,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function map($row): array
    {
        $base = [
            $row['employee_id'],
            $row['fullname'],
            $row['group_company'],
            $row['job_level'],
            $row['designation_name'],
            $row['unit'],
        ];

        return match ($this->reportType) {
            'talent_report' => [...$base, $row['potential'], $row['talent_box']],
            'idp_progress' => [...$base, $row['idp_progress']],
            default => $base,
        };
    }

    public function title(): string
    {
        return 'Report '.($this->year ?: 'All Years');
    }
}
