<?php

namespace App\Http\Controllers;

use App\Enums\UnitOfMeasurement;
use App\Http\Controllers\Concerns\ReadsPerPage;
use App\Http\Controllers\Concerns\ReadsSort;
use App\Http\Requests\ActOnIdpApprovalRequest;
use App\Http\Requests\SubmitIdpResultRequest;
use App\Models\DevelopmentModel;
use App\Models\IdpApproval;
use App\Models\IdpApprovalStep;
use App\Models\IndividualDevelopmentPlan;
use App\Models\User;
use App\Services\EmployeeScopeService;
use App\Services\Idp\ApprovalPresenter;
use App\Services\Idp\ApprovalSnapshot;
use App\Services\Idp\IdpStageService;
use App\Services\IdpApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The two-stage IDP workflow, from the acting side:
 *
 *  - submit the whole plan set for PLANNING approval (once per package);
 *  - file and submit one program's RESULT (realization + evidence), or every
 *    completed one at once;
 *  - approve / reject whatever is currently on your desk, with a note.
 *
 * The chains, the stamping and the notifications are IdpApprovalService's job;
 * the stage locks are IdpStageService's.
 */
class IdpApprovalController extends Controller
{
    use ReadsPerPage;
    use ReadsSort;

    /**
     * Sortable inbox columns → the path of the row value they sort on.
     */
    private const SORT_PATHS = [
        'owner_id' => 'owner_id',
        'owner_name' => 'owner_name',
        'stage' => 'stage',
        'title' => 'title',
        'submitted_at' => 'submitted_at',
        'level' => 'level',
    ];

    /**
     * The same, for the history view — which sorts on when the decision was
     * made, not on which layer is waiting. A separate whitelist so neither view
     * can be asked to sort on a column its rows do not carry.
     */
    private const HISTORY_SORT_PATHS = [
        'decided_at' => 'decided_at',
        'submitted_at' => 'submitted_at',
        'owner_name' => 'owner_name',
        'stage' => 'stage',
        'decision' => 'decision',
    ];

    public function __construct(
        private readonly EmployeeScopeService $scope,
        private readonly IdpApprovalService $approvals,
        private readonly IdpStageService $stage,
    ) {}

    // ------------------------------------------------------- stage 2: planning

    /**
     * Submit an employee's whole plan set for planning approval — the one
     * sign-off that opens the result stage for every program in it.
     */
    public function submitPlanning(Request $request, string $employeeId): RedirectResponse
    {
        abort_unless($this->scope->canManageIdp($request->user(), $employeeId), 403);

        $package = $this->stage->currentPackage();

        if (! $package) {
            return back()->with('error', 'There is no active model to submit a plan for.');
        }

        try {
            $this->approvals->submitPlanning($employeeId, $package->id, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', 'Development plan submitted for approval.');
    }

    // --------------------------------------------------------- stage 3: result

    /**
     * File one program's result. Saves the realization date + evidence and — by
     * default — sends it straight up the chain; `submit=false` only saves it, so
     * a result can be drafted and finished later.
     */
    public function saveResult(SubmitIdpResultRequest $request, IndividualDevelopmentPlan $idp): RedirectResponse
    {
        abort_unless($this->scope->canManageIdp($request->user(), $idp->employee_id), 403);

        if (! $idp->isPlanningApproved()) {
            return back()->with('error', 'The planning for this program has not been approved yet, so its result cannot be filed.');
        }

        $status = $idp->resultApproval()->value('status');

        if (in_array($status, ['pending', 'approved'], true)) {
            return back()->with('error', $status === 'pending'
                ? 'The result of this program is already awaiting approval.'
                : 'The result of this program has already been approved.');
        }

        $idp->update([
            'realization_date' => $request->validated('realization_date'),
            'achievement' => $request->validated('achievement'),
            'result_evidence' => $request->validated('result_evidence'),
        ]);

        if (! $request->shouldSubmit()) {
            return back()->with('success', 'Result saved. Submit it when you are ready.');
        }

        try {
            $this->approvals->submitResult($idp->refresh(), $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', 'Result submitted for approval.');
    }

    /**
     * Submit an already-filled result for approval.
     */
    public function submitResult(Request $request, IndividualDevelopmentPlan $idp): RedirectResponse
    {
        abort_unless($this->scope->canManageIdp($request->user(), $idp->employee_id), 403);

        try {
            $this->approvals->submitResult($idp, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', 'Result submitted for approval.');
    }

    /**
     * Submit every filled-in, not-yet-submitted result for an employee in one
     * go. Each still becomes its own request and is approved on its own — this
     * only saves the clicking.
     */
    public function submitAllResults(Request $request, string $employeeId): RedirectResponse
    {
        abort_unless($this->scope->canManageIdp($request->user(), $employeeId), 403);

        $plans = IndividualDevelopmentPlan::where('employee_id', $employeeId)
            ->whereNotNull('planning_approved_at')
            ->whereNotNull('realization_date')
            ->whereNotNull('result_evidence')
            ->get();

        $existing = IdpApproval::result()
            ->whereIn('individual_development_plan_id', $plans->pluck('id'))
            // Ascending, so keyBy keeps the latest round of each result.
            ->orderBy('id')
            ->get()
            ->keyBy('individual_development_plan_id');

        $submitted = 0;
        $lastError = null;

        foreach ($plans as $plan) {
            $status = $existing->get($plan->id)?->status;

            // Only (re)submit results that are not in flight or already signed off.
            if (! in_array($status, [null, 'rejected'], true)) {
                continue;
            }

            try {
                $this->approvals->submitResult($plan, $request->user());
                $submitted++;
            } catch (ValidationException $e) {
                $lastError = $e->validator->errors()->first();
            }
        }

        if ($submitted === 0) {
            return back()->with('error', $lastError ?? 'No results were ready to submit.');
        }

        return back()->with('success', "{$submitted} result(s) submitted for approval.");
    }

    // ------------------------------------------------------------- decisions

    public function approve(ActOnIdpApprovalRequest $request, IdpApproval $idpApproval): RedirectResponse
    {
        try {
            $this->approvals->approve($idpApproval, $request->user(), $request->note());
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', 'Approval recorded.');
    }

    public function reject(ActOnIdpApprovalRequest $request, IdpApproval $idpApproval): RedirectResponse
    {
        try {
            $this->approvals->reject($idpApproval, $request->user(), $request->note());
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', 'Rejection recorded.');
    }

    // ----------------------------------------------------------------- inbox

    /**
     * The signed-in user's approval desk, in two views:
     *
     *  - PENDING (the default) — every request they sit on the chain of, at
     *    either stage, as a searchable / sortable / paginated list. That is
     *    EVERY layer, not only the one whose turn it is: an approver reads a
     *    request from the moment it is submitted, and the card says whether it
     *    is theirs to decide yet.
     *  - HISTORY (`?view=history`) — the decisions they have already recorded,
     *    newest first, each with the note they left and where the request ended
     *    up afterwards.
     *
     * A planning request carries the whole plan set it covers, so the approver
     * reads the plan in one place rather than opening the IDP; a result request
     * carries its one program plus what was filed against it.
     *
     * Both sets are scoped to a single approver (so they stay small) and the
     * owner names come from the corporate DB, so search and sort are applied to
     * the resolved rows and then paginated in memory.
     */
    public function inbox(Request $request): Response
    {
        $user = $request->user();
        $history = $request->string('view')->value() === 'history';

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'stage' => $request->string('stage')->value(),
            'type' => $request->string('type')->value(),
        ];

        $paths = $history ? self::HISTORY_SORT_PATHS : self::SORT_PATHS;

        $sort = $this->readSort($request, array_keys($paths), $history ? 'decided_at' : 'submitted_at');

        // A log reads newest-first; readSort only ever defaults to ascending.
        if ($history && ! $request->filled('direction')) {
            $sort['dir'] = 'desc';
        }

        $rows = $history ? $this->historyRows($user) : $this->inboxRows($user);

        // Competency types across the whole list, not just the current page.
        $types = $rows
            ->flatMap(fn (array $row) => collect($row['plans'])->pluck('competency_type'))
            ->filter()->unique()->sort()->values();

        $path = $paths[$sort['key']];

        $matched = $rows
            ->when(
                $filters['stage'],
                fn (Collection $c, string $stage) => $c->where('stage', $stage),
            )
            ->when(
                $filters['type'],
                fn (Collection $c, string $type) => $c->filter(
                    fn (array $row) => collect($row['plans'])->contains('competency_type', $type),
                ),
            )
            ->when(
                $filters['search'],
                fn (Collection $c, string $term) => $c->filter(fn (array $row) => $this->matches($row, $term)),
            )
            ->sortBy(
                fn (array $row) => data_get($row, $path),
                SORT_NATURAL | SORT_FLAG_CASE,
                $sort['dir'] === 'desc',
            )
            ->values();

        $perPage = $this->perPage($request, 25);
        $page = LengthAwarePaginator::resolveCurrentPage();

        $items = new LengthAwarePaginator(
            $matched->forPage($page, $perPage)->values(),
            $matched->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        // The tab counts describe the whole desk, so each view has to supply
        // the other's total. Only the view being read is resolved into rows;
        // the counterpart is a count.
        $pending = $history ? collect() : $rows;

        return Inertia::render('Approvals/Inbox', [
            'items' => $items,
            'view' => $history ? 'history' : 'pending',
            'filters' => $filters,
            'sort' => $sort,
            'filterOptions' => ['types' => $types],
            // The unit catalogue, so a card can name the target's unit in the
            // reader's own language - the same payload the manage screen gets.
            'unitsOfMeasurement' => UnitOfMeasurement::options(),
            'pendingTotal' => $history ? $this->approvals->pendingCountFor($user) : $pending->count(),
            // Of those, the ones this user may decide right now — what the menu
            // badge counts. The rest are on the desk to be read, not acted on.
            'actionableTotal' => $history
                ? $this->approvals->actionableCountFor($user)
                : $pending->where('can_act', true)->count(),
            'stageTotals' => $history
                ? $this->pendingStageTotals($user)
                : [
                    'planning' => $pending->where('stage', IdpApproval::STAGE_PLANNING)->count(),
                    'result' => $pending->where('stage', IdpApproval::STAGE_RESULT)->count(),
                ],
            'historyTotal' => $history ? $rows->count() : $this->approvals->decidedCountFor($user),
        ]);
    }

    /**
     * Desk counts per stage, for the tab strip on the history view — where the
     * pending rows themselves are never resolved.
     *
     * @return array{planning: int, result: int}
     */
    private function pendingStageTotals(User $user): array
    {
        return $this->approvals->pendingStageCountsFor($user);
    }

    /**
     * Every request on this user's desk, shaped for the inbox — including the
     * ones still with an earlier layer, which they may read but not decide.
     *
     * Each row says which of the two it is (`can_act`) and, when it is not
     * theirs yet, which layer is holding it. The whole chain rides along so the
     * card can show where the request sits.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function inboxRows(User $user): Collection
    {
        $steps = $this->approvals->pendingFor($user);
        $presenter = new ApprovalPresenter;

        // Every approver on every chain shown, named in one query.
        $presenter->prime(
            $steps->flatMap(fn ($step) => $step->approval->steps->pluck('approver_employee_id')),
        );

        // A pending request with nothing left to approve is not worth showing:
        // the plans it covered have since been removed.
        $rows = $this->requestRows($steps, $presenter, requirePlans: true)->keyBy('step_id');

        return $steps->map(function (IdpApprovalStep $step) use ($rows, $presenter, $user) {
            $row = $rows->get($step->id);

            if (! $row) {
                return null;
            }

            $approval = $step->approval;
            $current = $approval->currentStep();

            return $row + [
                // Only the layer whose turn it is may decide. The same rule the
                // service enforces on the way in, so the card cannot offer a
                // button the server would refuse.
                'can_act' => $this->approvals->isCurrentApprover($approval, $user->employee_id),
                'awaiting_level' => $approval->current_level,
                'awaiting_name' => $presenter->name($current?->approver_employee_id),
                'chain' => $presenter->approval($approval, $user->employee_id),
            ];
        })->filter()->values();
    }

    /**
     * Every decision this user has recorded, shaped for the history view.
     *
     * Unlike the inbox, a request that no longer covers any plan is KEPT: the
     * decision was made and belongs in the log, whatever became of the programs
     * afterwards.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function historyRows(User $user): Collection
    {
        $steps = $this->approvals->decidedBy($user);
        $presenter = new ApprovalPresenter;

        // The whole chain is shown on a history card, so every approver on it
        // needs a name too — not just the owners the inbox resolves.
        $presenter->prime(
            $steps->flatMap(fn ($step) => $step->approval->steps->pluck('approver_employee_id')),
        );

        $rows = $this->requestRows($steps, $presenter, requirePlans: false)->keyBy('step_id');

        return $steps->map(function ($step) use ($rows, $presenter) {
            $row = $rows->get($step->id);

            if (! $row) {
                return null;
            }

            return $row + [
                'decision' => $step->status,
                'decided_at' => $step->acted_at?->toDateTimeString(),
                'note' => $step->note,
                // Signed off implicitly because this person submitted a request
                // they sit on the chain of — not a decision they weighed.
                'auto' => $step->isAutoApproved(),
                // Where the request went after this layer: still moving, signed
                // off by everyone, or declined further up.
                'outcome' => $step->approval->status,
                'chain' => $presenter->approval($step->approval),
            ];
        })->filter()->values();
    }

    /**
     * (step, its approval) pairs → the row shape both views of the desk read.
     *
     * The plans every request covers are loaded in two queries for the whole
     * set rather than per row: a planning request covers its package's entire
     * set, which would otherwise be a query each, and a history can run to any
     * length.
     *
     * @param  Collection<int, IdpApprovalStep>  $steps
     * @return Collection<int, array<string, mixed>>
     */
    private function requestRows(Collection $steps, ApprovalPresenter $presenter, bool $requirePlans): Collection
    {
        $approvals = $steps->map(fn ($step) => $step->approval)->filter();

        $presenter->prime($approvals->pluck('employee_id'));

        // package id => the models its planning approval covers.
        $packageIds = $approvals->filter->isPlanning()
            ->pluck('development_model_package_id')->filter()->unique()->values();

        $packageModels = $packageIds->isEmpty()
            ? collect()
            : DevelopmentModel::withTrashed()
                ->whereIn('development_model_package_id', $packageIds)
                ->get(['id', 'name', 'development_model_package_id'])
                ->groupBy('development_model_package_id');

        // Every plan of every employee with a planning request here, in one go.
        $planningOwners = $approvals->filter->isPlanning()->pluck('employee_id')->filter()->unique()->values();

        $plansByOwner = $planningOwners->isEmpty()
            ? collect()
            : IndividualDevelopmentPlan::whereIn('employee_id', $planningOwners)->get()->groupBy('employee_id');

        $pairs = $steps->map(function ($step) use ($packageModels, $plansByOwner) {
            $approval = $step->approval;

            if (! $approval) {
                return null;
            }

            // A request reads what it was submitted with. Only rows from before
            // snapshots were taken fall back to the plans as they are now.
            if ($approval->snapshot !== null) {
                return ['step' => $step, 'approval' => $approval, 'plans' => collect($approval->snapshot), 'frozen' => true];
            }

            if ($approval->isPlanning()) {
                $modelIds = ($packageModels[$approval->development_model_package_id] ?? collect())->pluck('id');

                $plans = ($plansByOwner[$approval->employee_id] ?? collect())
                    ->whereIn('development_model_id', $modelIds)
                    ->values();
            } else {
                $plans = collect(array_filter([$approval->plan]));
            }

            return ['step' => $step, 'approval' => $approval, 'plans' => $plans, 'frozen' => false];
        })->filter()->values();

        if ($requirePlans) {
            $pairs = $pairs->filter(fn (array $pair) => $pair['plans']->isNotEmpty())->values();
        }

        // Model names for every live plan on the desk, in one query — a planning
        // request's are already known from its package, and a snapshot carries
        // its own.
        $known = $packageModels->flatten()->pluck('name', 'id');

        $missing = $pairs->reject(fn (array $pair) => $pair['frozen'])
            ->flatMap(fn (array $pair) => $pair['plans']->pluck('development_model_id'))
            ->filter()->unique()->reject(fn ($id) => $known->has($id))->values();

        $modelNames = $missing->isEmpty()
            ? $known
            : $known->union(DevelopmentModel::withTrashed()->whereIn('id', $missing)->pluck('name', 'id'));

        $pairs = $pairs->map(fn (array $pair) => [
            ...$pair,
            'plans' => $pair['frozen']
                ? $pair['plans']->values()->all()
                : ApprovalSnapshot::of($pair['plans'], $modelNames),
        ]);

        $previous = $this->previousRounds($pairs->pluck('approval'), $presenter);

        return $pairs->map(fn (array $pair) => [
            // Identifies the ROW, not the request: one request appears twice in
            // a history when the same person sits on two of its layers.
            'step_id' => $pair['step']->id,
            'approval_id' => $pair['approval']->id,
            'stage' => $pair['approval']->stage,
            'level' => $pair['step']->level,
            'total_levels' => $pair['approval']->totalLevels(),
            'owner_id' => $pair['approval']->employee_id,
            'owner_name' => $presenter->name($pair['approval']->employee_id),
            'submitted_at' => $pair['approval']->submitted_at?->toDateTimeString(),
            'package' => $pair['approval']->package
                ? ['id' => $pair['approval']->package->id, 'name' => $pair['approval']->package->name]
                : null,
            // What the row is called in the list: the plan set for a planning
            // request, the one program for a result — as it was submitted.
            'title' => $pair['approval']->isPlanning()
                ? $pair['approval']->package?->name
                : ($pair['plans'][0]['development_program'] ?? $pair['approval']->plan?->development_program),
            'plans' => $pair['plans'],
            // False when the request predates snapshots, so its plans are the
            // live rows and may have moved on since it was decided.
            'frozen' => $pair['frozen'],
            'previous' => $this->comparison(
                $previous->get($pair['approval']->id),
                $pair['plans'],
                $pair['frozen'],
                $presenter,
            ),
        ])->values();
    }

    /**
     * The round before each of these requests — same employee + package for a
     * plan, same program for a result — keyed by the later request's id. Every
     * round of every subject on the desk is read in one query.
     *
     * @param  Collection<int, IdpApproval>  $approvals
     * @return Collection<int, IdpApproval>
     */
    private function previousRounds(Collection $approvals, ApprovalPresenter $presenter): Collection
    {
        $approvals = $approvals->unique('id');

        if ($approvals->isEmpty()) {
            return collect();
        }

        $planning = $approvals->filter->isPlanning();
        $results = $approvals->reject->isPlanning();

        $rounds = IdpApproval::query()
            ->with('steps')
            ->where(function ($query) use ($planning, $results) {
                if ($planning->isNotEmpty()) {
                    $query->orWhere(fn ($q) => $q->planning()
                        ->whereIn('employee_id', $planning->pluck('employee_id')->unique()->values())
                        ->whereIn('development_model_package_id', $planning->pluck('development_model_package_id')->unique()->values()));
                }

                if ($results->isNotEmpty()) {
                    $query->orWhere(fn ($q) => $q->result()
                        ->whereIn('individual_development_plan_id', $results->pluck('individual_development_plan_id')->unique()->values()));
                }
            })
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (IdpApproval $round) => $this->subjectKey($round));

        $found = $approvals->mapWithKeys(fn (IdpApproval $approval) => [
            $approval->id => ($rounds[$this->subjectKey($approval)] ?? collect())
                ->first(fn (IdpApproval $round) => $round->id < $approval->id),
        ])->filter();

        // Whoever declined the earlier round is named on the card.
        $presenter->prime($found->flatMap(fn (IdpApproval $round) => $round->steps->pluck('approver_employee_id')));

        return $found;
    }

    private function subjectKey(IdpApproval $approval): string
    {
        return $approval->isPlanning()
            ? "planning|{$approval->employee_id}|{$approval->development_model_package_id}"
            : "result|{$approval->individual_development_plan_id}";
    }

    /**
     * How this request differs from the round before it, and how that round
     * ended. Null on a first round. `diff` is null when either side has no
     * snapshot: two live reads of the same rows would always say "nothing
     * changed", which is exactly the misreading this exists to stop.
     *
     * @param  list<array<string, mixed>>  $plans
     * @return array<string, mixed>|null
     */
    private function comparison(?IdpApproval $round, array $plans, bool $frozen, ApprovalPresenter $presenter): ?array
    {
        if (! $round) {
            return null;
        }

        $decisive = $round->steps->firstWhere('status', 'rejected');

        return [
            'approval_id' => $round->id,
            'submitted_at' => $round->submitted_at?->toDateTimeString(),
            'status' => $round->status,
            // Who stopped it and why — usually the reason the new round exists.
            'rejected' => $decisive ? [
                'level' => $decisive->level,
                'name' => $decisive->acted_by_name
                    ?: $presenter->name($decisive->approver_employee_id)
                    ?: $decisive->approver_employee_id,
                'note' => $decisive->note,
                'at' => $decisive->acted_at?->toDateTimeString(),
            ] : null,
            'diff' => $frozen && $round->snapshot !== null
                ? ApprovalSnapshot::diff($round->snapshot, $plans)
                : null,
        ];
    }

    /**
     * Free-text match across the employee and everything the request covers.
     *
     * @param  array<string, mixed>  $row
     */
    private function matches(array $row, string $term): bool
    {
        $term = mb_strtolower($term);

        $haystack = collect([
            $row['owner_id'],
            $row['owner_name'],
            $row['title'],
            // Only a history row carries one; searching your own notes is the
            // quickest way back to a decision you remember making.
            $row['note'] ?? null,
        ])->merge(
            collect($row['plans'])->flatMap(fn (array $plan) => [
                $plan['competency_name'],
                $plan['competency_type'],
                $plan['development_program'],
            ]),
        );

        foreach ($haystack as $value) {
            if (filled($value) && str_contains(mb_strtolower((string) $value), $term)) {
                return true;
            }
        }

        return false;
    }
}
