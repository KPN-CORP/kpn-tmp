<?php

namespace App\Exports;

use App\Models\IndividualDevelopmentPlan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Exports one employee's development plans to a spreadsheet.
 */
class IdpExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly string $employeeId)
    {
    }

    public function collection(): Collection
    {
        return IndividualDevelopmentPlan::with('developmentModel:id,name')
            ->where('employee_id', $this->employeeId)
            ->orderBy('development_model_id')
            ->orderByDesc('id')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Development Model', 'Competency Type', 'Competency Name', 'Development Program',
            'Review Tools', 'Expected Outcome', 'Start', 'End', 'Realization', 'Result / Evidence',
        ];
    }

    /**
     * @param  IndividualDevelopmentPlan  $plan
     */
    public function map($plan): array
    {
        return [
            $plan->developmentModel?->name,
            $plan->competency_type,
            $plan->competency_name,
            $plan->development_program,
            $plan->review_tools,
            $plan->expected_outcome,
            optional($plan->time_frame_start)->toDateString(),
            optional($plan->time_frame_end)->toDateString(),
            optional($plan->realization_date)->toDateString(),
            $plan->result_evidence,
        ];
    }
}
