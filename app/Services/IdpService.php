<?php

namespace App\Services;

use App\Models\Competency;
use App\Models\CompetencyType;
use App\Models\DevelopmentModel;
use App\Models\DevelopmentModelPackage;
use App\Models\DevelopmentProgram;
use App\Models\IdpApproval;
use App\Models\IndividualDevelopmentPlan;
use App\Models\ReviewTool;
use App\Models\User;
use App\Services\Idp\ApprovalPresenter;
use App\Services\Idp\IdpStageService;
use Illuminate\Support\Collection;

/**
 * Assembles the data the IDP "manage" screen needs: the development models, an
 * employee's plans grouped by model, the master-driven dropdown options, and
 * the competency→programs map that drives the competency cascade.
 *
 * On top of that it reports where the plan set stands in the two-stage
 * workflow — the package-wide PLANNING approval, and each program's own RESULT
 * approval — together with what the viewer may do at each: edit a plan, submit
 * the planning, file a result, or decide on one.
 */
class IdpService
{
    public function __construct(
        private readonly ApprovalChainService $chain,
        private readonly IdpStageService $stage,
    ) {}

    /**
     * @param  User|null  $viewer  the signed-in user (drives can_act)
     * @param  bool  $canManage  whether the viewer may edit / submit this IDP
     * @param  int|null  $packageId  the development-model package to show; the
     *                               active one when omitted or unknown
     */
    public function manageData(
        string $employeeId,
        ?User $viewer = null,
        bool $canManage = false,
        ?int $packageId = null,
    ): array {
        $allPlans = IndividualDevelopmentPlan::where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->get();

        $plans = $allPlans->groupBy('development_model_id');

        // The screen shows ONE cycle at a time. Which one is the viewer's
        // choice; the active package is the default, since that is the only one
        // a plan can still be written in.
        $activePackage = DevelopmentModelPackage::active();
        $activePackageId = $activePackage?->id;

        $packages = $this->stage->packageOptions($activePackageId, $this->planCounts($allPlans));

        $selectedPackageId = $this->stage->selectedPackageId($packages, $packageId, $activePackageId);

        $selectedPackage = $selectedPackageId === $activePackageId
            ? $activePackage
            : DevelopmentModelPackage::find($selectedPackageId);

        // A closed cycle is read-only: its plans stay visible, but nothing about
        // them can be added, changed, submitted or reported on any more.
        $viewingActive = $selectedPackageId !== null && $selectedPackageId === $activePackageId;
        $canManage = $canManage && $viewingActive;

        $models = DevelopmentModel::when(
            $selectedPackageId,
            fn ($q) => $q->where('development_model_package_id', $selectedPackageId),
            fn ($q) => $q->whereRaw('1 = 0'),
        )->orderByDesc('percentage')->orderBy('name')->get();

        $activeIds = $viewingActive ? $models->pluck('id')->all() : [];

        // Only the selected cycle's plans are rendered, so a row from another
        // package never leaks onto the screen.
        $allPlans = $allPlans->filter(
            fn ($p) => $models->contains('id', $p->development_model_id),
        )->values();

        $workflow = $this->workflow($employeeId, $selectedPackage, $models, $allPlans, $viewer, $canManage);

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
                'can_add' => in_array($m->id, $activeIds, true) && $workflow['planning']['plans_editable'],
                'is_active_package' => in_array($m->id, $activeIds, true),
                'plans' => ($plans->get($m->id) ?? collect())
                    ->map(fn ($p) => array_merge($p->toArray(), ['stage' => $workflow['planFlags'][$p->id]]))
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
            'planning' => $workflow['planning'],
            'progress' => $workflow['progress'],
            // The cycle picker: every package, newest first, with this
            // employee's plan count so an empty one is obvious before it is
            // opened. The screen shows one at a time.
            'packages' => $packages->all(),
            'selectedPackageId' => $selectedPackageId,
            // False for a closed cycle — the whole screen is then read-only.
            'viewingActive' => $viewingActive,
        ];
    }

    /**
     * How many of this employee's plans sit in each package, for the cycle
     * picker: an empty cycle is obvious before it is opened.
     *
     * Soft-deleted models are not rendered, so their plans are not counted
     * either — the number has to match what opening the package shows.
     *
     * @param  Collection<int, IndividualDevelopmentPlan>  $allPlans
     * @return array<int, int>
     */
    private function planCounts(Collection $allPlans): array
    {
        $packageOfModel = DevelopmentModel::pluck('development_model_package_id', 'id');

        return $allPlans
            ->groupBy(fn ($plan) => $packageOfModel[$plan->development_model_id] ?? 0)
            ->map->count()
            ->all();
    }

    /**
     * The two-stage state of an employee's plan set: the package-wide planning
     * approval, the per-row flags the table renders, and the headline counts the
     * stage tracker shows.
     *
     * @param  Collection<int, DevelopmentModel>  $models
     * @param  Collection<int, IndividualDevelopmentPlan>  $allPlans
     * @return array{planning: array<string, mixed>, planFlags: array<int, array<string, mixed>>, progress: array<string, int>}
     */
    private function workflow(
        string $employeeId,
        ?DevelopmentModelPackage $selectedPackage,
        Collection $models,
        Collection $allPlans,
        ?User $viewer,
        bool $canManage,
    ): array {
        $viewerEmpId = $viewer?->employee_id;
        $presenter = new ApprovalPresenter;

        // modelId => packageId, so each plan can be attributed to the planning
        // approval that covers it. A plan under a previous package answers to
        // that package's own approval, not the active one.
        $packageOfModel = $models->pluck('development_model_package_id', 'id');

        $packageIds = $packageOfModel->values()
            ->merge([$selectedPackage?->id])
            ->filter()
            ->unique()
            ->values();

        // The current (latest) planning approval for every package in play.
        $planningApprovals = IdpApproval::planning()
            ->where('employee_id', $employeeId)
            ->whereIn('development_model_package_id', $packageIds->all() ?: [0])
            ->with('steps')
            ->orderBy('id')
            ->get()
            // Ascending order means the last one written wins — the latest
            // revision, which is the current approval for that package.
            ->keyBy('development_model_package_id');

        $resultApprovals = IdpApproval::result()
            ->where('employee_id', $employeeId)
            ->with('steps')
            ->get()
            ->keyBy('individual_development_plan_id');

        $chainLayers = $this->chain->layersFor($employeeId);

        $presenter->prime(
            $presenter->approverIds($planningApprovals->values()->merge($resultApprovals->values()))
                ->merge($chainLayers),
        );

        $chainPreview = $presenter->preview($chainLayers);

        // --- Per-plan flags -------------------------------------------------
        $planFlags = [];

        foreach ($allPlans as $plan) {
            $packageId = $packageOfModel[$plan->development_model_id] ?? null;
            $planning = $packageId !== null ? $planningApprovals->get($packageId) : null;
            $frozen = ! $this->stage->plansEditable($planning);

            $result = $resultApprovals->get($plan->id);
            $resultStatus = $result?->status;
            $settled = in_array($resultStatus, ['pending', 'approved'], true);

            $planFlags[$plan->id] = [
                'planning_approved' => $plan->isPlanningApproved(),
                'planning_approved_at' => $plan->planning_approved_at?->toDateTimeString(),
                // The planning fields are editable while the set is not in
                // flight and the result has not been settled on top of them.
                'can_edit' => $canManage && ! $frozen && ! $settled,
                'can_delete' => $canManage && ! $frozen && ! $settled,
                'frozen_by_planning' => $frozen,
                'result' => [
                    'status' => $plan->isPlanningApproved() ? ($resultStatus ?? 'open') : 'locked',
                    'filled' => $plan->isRealized(),
                    'approval' => $result ? $presenter->approval($result, $viewerEmpId) : null,
                    'chain_preview' => $result ? null : $chainPreview,
                    // The result may be filed (or re-filed after a rejection)
                    // once this row's planning is signed off.
                    'can_submit' => $canManage
                        && $plan->isPlanningApproved()
                        && in_array($resultStatus, [null, 'rejected'], true),
                    'can_act' => $result
                        ? $presenter->approval($result, $viewerEmpId)['can_act']
                        : false,
                ],
            ];
        }

        // --- The package-wide planning stage --------------------------------
        $selectedId = $selectedPackage?->id;
        $currentPlanning = $selectedId ? $planningApprovals->get($selectedId) : null;

        $packagePlans = $selectedId
            ? $allPlans->filter(fn ($p) => ($packageOfModel[$p->development_model_id] ?? null) === $selectedId)
            : collect();

        $status = $this->stage->planningStatus($currentPlanning, $packagePlans);
        $awaiting = $packagePlans->reject->isPlanningApproved()->count();

        $planning = [
            'package' => $selectedPackage ? [
                'id' => $selectedPackage->id,
                'name' => $selectedPackage->name,
                'start_date' => $selectedPackage->start_date?->toDateString(),
                'end_date' => $selectedPackage->end_date?->toDateString(),
            ] : null,
            'status' => $status,
            'approval' => $currentPlanning ? $presenter->approval($currentPlanning, $viewerEmpId) : null,
            'chain_preview' => $chainPreview,
            'history' => $this->stage
                ->planningHistory($employeeId, $selectedId, $currentPlanning?->id)
                ->map(fn (IdpApproval $a) => $presenter->approval($a, $viewerEmpId))
                ->values()
                ->all(),
            // The set may be submitted when it holds plans, is not already in
            // flight, and has something new to approve.
            'can_submit' => $canManage
                && $selectedId !== null
                && $packagePlans->isNotEmpty()
                && $status !== IdpStageService::PENDING
                && $awaiting > 0,
            'plans_editable' => $this->stage->plansEditable($currentPlanning),
            'total_plans' => $packagePlans->count(),
            'awaiting_plans' => $awaiting,
            'has_approvers' => ! empty($chainLayers),
        ];

        // --- Headline counts for the stage tracker --------------------------
        $resultStates = $packagePlans->map(fn ($p) => $planFlags[$p->id]['result']['status']);

        $progress = [
            'plans' => $packagePlans->count(),
            'planning_approved' => $packagePlans->filter->isPlanningApproved()->count(),
            'result_open' => $resultStates->filter(fn ($s) => $s === 'open')->count(),
            'result_pending' => $resultStates->filter(fn ($s) => $s === 'pending')->count(),
            'result_approved' => $resultStates->filter(fn ($s) => $s === 'approved')->count(),
            'result_rejected' => $resultStates->filter(fn ($s) => $s === 'rejected')->count(),
            'result_locked' => $resultStates->filter(fn ($s) => $s === 'locked')->count(),
        ];

        return ['planning' => $planning, 'planFlags' => $planFlags, 'progress' => $progress];
    }
}
