<?php

namespace App\Services;

use App\Models\Competency;
use App\Models\CompetencyType;
use App\Models\DevelopmentModel;
use App\Models\DevelopmentModelPackage;
use App\Models\DevelopmentProgram;
use App\Models\Employee;
use App\Models\IdpApproval;
use App\Models\IndividualDevelopmentPlan;
use App\Models\ReviewTool;
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
        $plans = IndividualDevelopmentPlan::where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->get()
            ->groupBy('development_model_id');

        // New plans may only be filed under the active package's models. Older
        // plans still point at models from a previous package, so include those
        // (read-only) too and flag which models accept new plans.
        $activePackageId = DevelopmentModelPackage::active()?->id;

        $activeModels = DevelopmentModel::when(
            $activePackageId,
            fn ($q) => $q->where('development_model_package_id', $activePackageId),
            fn ($q) => $q->whereRaw('1 = 0'),
        )->orderByDesc('percentage')->orderBy('name')->get();

        $activeIds = $activeModels->pluck('id')->all();

        $historicalModels = DevelopmentModel::whereIn('id', $plans->keys()->filter())
            ->whereNotIn('id', $activeIds ?: [0])
            ->orderByDesc('percentage')->orderBy('name')->get();

        $models = $activeModels->concat($historicalModels);

        $approvalFor = $this->approvalResolver($employeeId, $viewer, $canManage);

        $programs = DevelopmentProgram::with('competencyType:id,name_en')
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_id', 'development_model_id', 'competency_type_id']);

        // Only active masters are offered for new plans. Plans store the name
        // verbatim, so items already picked from a since-deactivated competency
        // / review tool keep displaying — same read-only treatment the
        // historical development models get above.
        $competencies = Competency::with([
            'competencyType:id,name_en',
            'developmentPrograms:id,name_en,name_id,development_model_id,competency_type_id',
            'developmentPrograms.competencyType:id,name_en',
        ])
            ->active()
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_id', 'competency_type_id']);

        $reviewTools = ReviewTool::active()->orderBy('name_en')->get(['id', 'name_en', 'name_id']);

        // The competency types come from the master table rather than a
        // hard-coded pair. A plan stores the type's NAME verbatim (the column is
        // a string, not an FK), so the option value is `name_en` like every
        // other master here.
        $competencyTypes = CompetencyType::orderBy('name_en')->get(['id', 'name_en', 'name_id']);

        // Shape a master row into a localizable option. `value` is the canonical
        // name that IDP rows store and match on; value_en/value_id drive the
        // display label. `competency_type` is the master's competency type as
        // the plan stores it (a name string, not an id) — null when the master
        // is untyped, which by convention makes it global and fits every type.
        $option = fn ($m) => [
            'value' => $m->name_en,
            'value_en' => $m->name_en,
            'value_id' => $m->name_id,
            'competency_type' => $m->competencyType?->name_en,
        ];

        // A development program additionally carries the development model it is
        // filed under (the 70-20-10 split). A plan is added under one model, so
        // the picker only offers that model's programs. Null is legacy data with
        // no model, which — like an untyped master — counts as global.
        $programOption = fn (DevelopmentProgram $p) => $option($p) + ['model_id' => $p->development_model_id];

        // competency name (lower-cased) => [{ value, value_en, value_id,
        // competency_type, model_id }] — the programs that build it, so the
        // plan form can narrow the program picker to the chosen competency.
        $competencyMap = [];
        foreach ($competencies as $competency) {
            $linked = $competency->developmentPrograms
                ->map($programOption)
                ->values();

            if ($linked->isNotEmpty()) {
                $competencyMap[strtolower(trim($competency->name_en))] = $linked;
            }
        }

        return [
            'developmentModels' => $models->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'percentage' => $m->percentage,
                'description_en' => $m->description_en,
                'description_id' => $m->description_id,
                // Only active-package models accept new plans; historical ones
                // are shown read-only so past plans stay visible.
                'can_add' => in_array($m->id, $activeIds, true),
                'plans' => ($plans->get($m->id) ?? collect())
                    ->map(fn ($p) => array_merge($p->toArray(), ['approval' => $approvalFor($p)]))
                    ->values(),
            ]),
            'options' => [
                // Every type — the catch-all "Others" included — picks its
                // competency from the master data, so a type carries nothing
                // beyond its name.
                'competencyTypes' => $competencyTypes->map($option)->values(),
                'competencyNames' => $competencies->map($option)->values(),
                // Deduped per model, not globally: a program name is unique
                // within a development model, so the same name may legitimately
                // exist under two models as two different programs.
                'developmentPrograms' => $programs->map($programOption)
                    ->unique(fn (array $p) => $p['model_id'].'|'.strtolower(trim((string) $p['value'])))
                    ->values(),
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
