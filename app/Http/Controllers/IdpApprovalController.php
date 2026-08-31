<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsSort;
use App\Http\Requests\ActOnIdpApprovalRequest;
use App\Models\Employee;
use App\Models\IdpApproval;
use App\Models\IndividualDevelopmentPlan;
use App\Models\User;
use App\Services\EmployeeScopeService;
use App\Services\IdpApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The IDP approval runtime: submit an item (or every pending item) for approval,
 * and let the current-layer approver approve / reject with a note. The approval
 * chain and the "need approval" alerts are handled by IdpApprovalService.
 */
class IdpApprovalController extends Controller
{
    use ReadsSort;

    /**
     * Sortable inbox columns → the path of the row value they sort on.
     */
    private const SORT_PATHS = [
        'owner_id' => 'owner_id',
        'owner_name' => 'owner_name',
        'competency_name' => 'plan.competency_name',
        'development_program' => 'plan.development_program',
        'submitted_at' => 'submitted_at',
        'level' => 'level',
    ];

    public function __construct(
        private readonly EmployeeScopeService $scope,
        private readonly IdpApprovalService $approvals,
    ) {}

    /**
     * Submit one IDP item for approval.
     */
    public function submit(Request $request, IndividualDevelopmentPlan $idp): RedirectResponse
    {
        abort_unless($this->scope->canView($request->user(), $idp->employee_id), 403);

        try {
            $this->approvals->submit($idp, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', 'Item submitted for approval.');
    }

    /**
     * Submit every not-yet-submitted (draft or rejected) IDP item for an
     * employee in one go.
     */
    public function submitAll(Request $request, string $employeeId): RedirectResponse
    {
        abort_unless($this->scope->canView($request->user(), $employeeId), 403);

        $plans = IndividualDevelopmentPlan::where('employee_id', $employeeId)->get();

        $existing = IdpApproval::whereIn('individual_development_plan_id', $plans->pluck('id'))
            ->get()
            ->keyBy('individual_development_plan_id');

        $submitted = 0;
        $lastError = null;

        foreach ($plans as $plan) {
            $status = $existing->get($plan->id)?->status;
            // Only (re)submit items that are not currently in-flight or approved.
            if (! in_array($status, [null, 'rejected'], true)) {
                continue;
            }

            // ...and only once the item is completed (realized).
            if (blank($plan->realization_date)) {
                continue;
            }

            try {
                $this->approvals->submit($plan, $request->user());
                $submitted++;
            } catch (ValidationException $e) {
                $lastError = $e->validator->errors()->first();
            }
        }

        if ($submitted === 0) {
            return back()->with('error', $lastError ?? 'No items were eligible for submission.');
        }

        return back()->with('success', "{$submitted} item(s) submitted for approval.");
    }

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

    /**
     * The signed-in user's approval inbox — items awaiting their decision, as a
     * searchable / sortable / paginated table.
     *
     * The pending set is already scoped to a single approver (so it stays
     * small) and the owner names come from the corporate DB, so search and sort
     * are applied to the resolved rows and then paginated in memory.
     */
    public function inbox(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'type' => $request->string('type')->value(),
        ];

        $sort = $this->readSort($request, array_keys(self::SORT_PATHS), 'submitted_at');

        $rows = $this->inboxRows($request->user());

        // Competency types across the whole inbox, not just the current page.
        $types = $rows->pluck('plan.competency_type')->filter()->unique()->sort()->values();

        $path = self::SORT_PATHS[$sort['key']];

        $matched = $rows
            ->when(
                $filters['type'],
                fn (Collection $c, string $type) => $c->where('plan.competency_type', $type),
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

        $perPage = max(1, (int) $request->integer('per_page', 25));
        $page = LengthAwarePaginator::resolveCurrentPage();

        $items = new LengthAwarePaginator(
            $matched->forPage($page, $perPage)->values(),
            $matched->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('Approvals/Inbox', [
            'items' => $items,
            'filters' => $filters,
            'sort' => $sort,
            'filterOptions' => ['types' => $types],
            'pendingTotal' => $rows->count(),
        ]);
    }

    /**
     * Every approval step awaiting this user's decision, shaped for the table.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function inboxRows(User $user): Collection
    {
        $steps = $this->approvals->pendingFor($user);

        // Resolve owner names in one guarded query.
        $names = $this->resolveNames($steps->map(fn ($step) => $step->approval?->employee_id));

        return $steps->map(function ($step) use ($names) {
            $approval = $step->approval;
            $plan = $approval?->plan;

            return [
                'approval_id' => $approval?->id,
                'level' => $step->level,
                'total_levels' => $approval?->totalLevels(),
                'owner_id' => $approval?->employee_id,
                'owner_name' => $names[$approval?->employee_id] ?? $approval?->employee_id,
                'submitted_at' => $approval?->submitted_at?->toDateTimeString(),
                'plan' => $plan ? [
                    'id' => $plan->id,
                    'competency_type' => $plan->competency_type,
                    'competency_name' => $plan->competency_name,
                    'development_program' => $plan->development_program,
                    'expected_outcome' => $plan->expected_outcome,
                    'time_frame_start' => $plan->time_frame_start?->toDateString(),
                    'time_frame_end' => $plan->time_frame_end?->toDateString(),
                ] : null,
            ];
        })->filter(fn ($row) => $row['approval_id'] !== null && $row['plan'] !== null)->values();
    }

    /**
     * Free-text match across the employee, competency and program columns.
     *
     * @param  array<string, mixed>  $row
     */
    private function matches(array $row, string $term): bool
    {
        $term = mb_strtolower($term);

        $haystack = [
            $row['owner_id'],
            $row['owner_name'],
            data_get($row, 'plan.competency_name'),
            data_get($row, 'plan.competency_type'),
            data_get($row, 'plan.development_program'),
        ];

        foreach ($haystack as $value) {
            if (filled($value) && str_contains(mb_strtolower((string) $value), $term)) {
                return true;
            }
        }

        return false;
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
