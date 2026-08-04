<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Exports a (scoped, filtered) employee query to a spreadsheet.
 */
class EmployeeExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Builder $query)
    {
    }

    public function collection(): Collection
    {
        return $this->query->orderBy('fullname')->get();
    }

    public function headings(): array
    {
        return ['Employee ID', 'Name', 'Business Unit', 'Company', 'Designation', 'Job Level', 'Unit', 'Email'];
    }

    /**
     * @param  \App\Models\Employee  $employee
     */
    public function map($employee): array
    {
        return [
            $employee->employee_id,
            $employee->fullname,
            $employee->group_company,
            $employee->company_name,
            $employee->designation_name,
            $employee->job_level,
            $employee->unit,
            $employee->email,
        ];
    }
}
