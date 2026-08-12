<?php

namespace App\Services;

use App\Models\DevelopmentModel;
use App\Models\DevelopmentPlanMaster;
use App\Models\Employee;
use App\Models\IdpApproval;
use App\Models\IndividualDevelopmentPlan;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Assembles the data the IDP "manage" screen needs: the development models, an
 * employee's plans grouped by model, the master-driven dropdown options, and
 * the competency→programs map that drives the soft-competency cascade.
 *
 * Each plan also carries its approval state (status, staged L1→L2 chain, and
 * whether the viewer may submit or act) so the panel can render the workflow.
 */
class IdpService
{
    public function __construct(private readonly ApprovalChainService $chain) {}

    /**
     * @param  User|null  $viewer  the signed-in user (drives can_act)
     * @param  bool  $canManage  whether the viewer may edit / submit this IDP
     */
    public function manageData(string $employeeId, ?User $viewer = null, bool $canManage = false): array
    {
        $models = DevelopmentModel::orderByDesc('percentage')->orderBy('name')->get();

        $plans = IndividualDevelopmentPlan::where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->get()
            ->groupBy('development_model_id');

        $approvalFor = $this->approvalResolver($employeeId, $viewer, $canManage);

        $programs = DevelopmentPlanMaster::where('type', 'development_program')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'development_model_id']);

        $programsById = $programs->keyBy('id');

        $competencies = DevelopmentPlanMaster::where('type', 'competency_name')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'related_program']);

        $reviewTools = DevelopmentPlanMaster::where('type', 'review_tools')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id']);

        // Shape a master row into a localizable option (canonical value stays the
        // stored/matched key; value_en/value_id drive the display label).
        $option = fn ($m) => [
            'value' => $m->value,
            'value_en' => $m->value_en,
            'value_id' => $m->value_id,
        ];

        // competency value (lower-cased) => [{ value, value_en, value_id, model_id }]
        $competencyMap = [];
        foreach ($competencies as $competency) {
            $linked = collect($competency->related_program ?? [])
                ->map(fn ($id) => $programsById->get($id))
                ->filter()
                ->map(fn ($p) => [
                    'value' => $p->value,
                    'value_en' => $p->value_en,
                    'value_id' => $p->value_id,
                    'model_id' => $p->development_model_id,
                ])
                ->values();

            if ($linked->isNotEmpty()) {
                $competencyMap[strtolower(trim($competency->value))] = $linked;
            }
        }

        return [
            'developmentModels' => $models->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'percentage' => $m->percentage,
                'description_en' => $m->description_en,
                'description_id' => $m->description_id,
                'plans' => ($plans->get($m->id) ?? collect())
                    ->map(fn ($p) => array_merge($p->toArray(), ['approval' => $approvalFor($p)]))
                    ->values(),
            ]),
            'options' => [
                'competencyNames' => $competencies->map($option)->values(),
                'developmentPrograms' => $programs->map($option)->unique('value')->values(),
                'reviewTools' => $reviewTools->map($option)->values(),
            ],
            'competencyMap' => $competencyMap,
        ];
    }

    /**
     * Build a closure that maps an IDP item to its approval payload:
     *
     *   - status:        draft | pending | approved | rejected
     *   - current_level: which layer's turn it is (pending only)
     *   - total_levels:  number of approval layers
     *   - steps:         the L1→L2→… chain, each with approver + decision + note
     *   - can_submit:    the viewer may (re)submit this item
     *   - can_act:       the viewer is the approver whose turn it currently is
     *
     * For not-yet-submitted items the chain is previewed from the employee's
     * effective approval layers so the UI can show where it will go.
     */
    private function approvalResolver(string $employeeId, ?User $viewer, bool $canManage): \Closure
    {
        $approvals = IdpApproval::where('employee_id', $employeeId)
            ->with('steps')
            ->get()
            ->keyBy('individual_development_plan_id');

        $chainLayers = $this->chain->layersFor($employeeId);

        // Resolve every referenced approver id to a name in one guarded query.
        $ids = $approvals->flatMap(fn ($a) => $a->steps->pluck('approver_employee_id'))
            ->merge($chainLayers)
            ->filter()->unique()->values();
        $names = $this->resolveNames($ids);

        $viewerEmpId = $viewer?->employee_id;

        $mapStep = fn ($step) => [
            'level' => $step->level,
            'approver_id' => $step->approver_employee_id,
            'approver_name' => $names[$step->approver_employee_id] ?? $step->approver_employee_id,
            'status' => $step->status,
            'note' => $step->note,
            'acted_by_name' => $step->acted_by_name,
            'acted_at' => $step->acted_at?->toDateTimeString(),
        ];

        // The would-be chain shown for draft / not-yet-submitted items.
        $chainPreview = collect($chainLayers)->values()->map(fn ($id, $i) => [
            'level' => $i + 1,
            'approver_id' => $id,
            'approver_name' => $names[$id] ?? $id,
            'status' => 'pending',
            'note' => null,
            'acted_by_name' => null,
            'acted_at' => null,
        ])->all();

        return function ($plan) use ($approvals, $mapStep, $chainPreview, $chainLayers, $viewerEmpId, $canManage) {
            $appr = $approvals->get($plan->id);
            $status = $appr?->status ?? 'draft';

            $steps = $appr ? $appr->steps->map($mapStep)->values()->all() : $chainPreview;
            $current = $appr?->currentStep();

            $canAct = $viewerEmpId
                && $appr
                && $status === 'pending'
                && $current
                && $current->approver_employee_id === $viewerEmpId;

            return [
                'id' => $appr?->id,
                'status' => $status,
                'current_level' => $appr?->current_level,
                'total_levels' => $appr ? $appr->totalLevels() : count($chainLayers),
                'submitted_at' => $appr?->submitted_at?->toDateTimeString(),
                'steps' => $steps,
                // Only a completed (realized) item can be submitted for approval.
                'can_submit' => $canManage
                    && in_array($status, ['draft', 'rejected'], true)
                    && filled($plan->realization_date),
                'can_act' => (bool) $canAct,
            ];
        };
    }

    /**
     * @param  Collection<int, string>  $ids
     * @return array<string, string>
     */
    private function resolveNames($ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        try {
            return Employee::whereIn('employee_id', $ids)->pluck('fullname', 'employee_id')->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
