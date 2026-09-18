<?php

namespace App\Exports;

use App\Models\IdpApproval;
use App\Models\IndividualDevelopmentPlan;
use App\Services\Idp\IdpStageService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Exports one employee's development plans for ONE cycle — the same cycle the
 * screen was showing, so the file matches what was on it.
 *
 * It carries the two-stage state as well as the plan itself: a sheet that shows
 * a realization date without saying whether it was approved misreports the work.
 */
class IdpExport implements FromCollection, WithHeadings, WithMapping
{
    /** plan id => its result approval status */
    private array $resultStatus = [];

    public function __construct(
        private readonly string $employeeId,
        private readonly ?int $packageId = null,
        private readonly IdpStageService $stage = new IdpStageService,
    ) {}

    public function collection(): Collection
    {
        $packageId = $this->packageId ?? $this->stage->currentPackage()?->id;

        $plans = IndividualDevelopmentPlan::with('developmentModel:id,name')
            ->where('employee_id', $this->employeeId)
            ->when(
                $packageId,
                fn ($q) => $q->whereIn('development_model_id', $this->stage->modelIdsFor($packageId) ?: [0]),
                // No cycle at all to scope by — export nothing rather than
                // silently mixing every cycle together.
                fn ($q) => $q->whereRaw('1 = 0'),
            )
            ->orderBy('development_model_id')
            ->orderByDesc('id')
            ->get();

        $this->resultStatus = IdpApproval::result()
            ->whereIn('individual_development_plan_id', $plans->pluck('id'))
            ->pluck('status', 'individual_development_plan_id')
            ->all();

        return $plans;
    }

    public function headings(): array
    {
        return [
            'Development Model', 'Competency Type', 'Competency Name', 'Development Program',
            'Review Tools', 'Expected Outcome', 'Start', 'End',
            'Planning Approved', 'Realization', 'Result / Evidence', 'Result Approval',
        ];
    }

    /**
     * @param  IndividualDevelopmentPlan  $plan
     */
    public function map($plan): array
    {
        $result = $this->resultStatus[$plan->id] ?? null;

        return [
            $plan->developmentModel?->name,
            $plan->competency_type,
            $plan->competency_name,
            $plan->development_program,
            $plan->review_tools,
            $plan->expected_outcome,
            $plan->time_frame_start?->toDateString(),
            $plan->time_frame_end?->toDateString(),
            $plan->planning_approved_at?->toDateString() ?? 'Not approved',
            $plan->realization_date?->toDateString(),
            $plan->result_evidence,
            // A row with no approval has either not been filed or not been sent;
            // the realization column beside it says which.
            match ($result) {
                'pending' => 'Awaiting approval',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                default => $plan->realization_date ? 'Not submitted' : '—',
            },
        ];
    }
}
