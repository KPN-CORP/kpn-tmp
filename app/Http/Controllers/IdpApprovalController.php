<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActOnIdpApprovalRequest;
use App\Models\Employee;
use App\Models\IdpApproval;
use App\Models\IndividualDevelopmentPlan;
use App\Services\EmployeeScopeService;
use App\Services\IdpApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * The signed-in user's approval inbox — items awaiting their decision.
     */
    public function inbox(Request $request): Response
    {
        $steps = $this->approvals->pendingFor($request->user());

        // Resolve owner + approver names in one guarded query.
        $ids = $steps->flatMap(fn ($s) => [$s->approval?->employee_id])
            ->merge($steps->pluck('approver_employee_id'))
            ->filter()->unique();
        $names = $this->resolveNames($ids);

        $items = $steps->map(function ($step) use ($names) {
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
        })->filter(fn ($i) => $i['approval_id'] !== null && $i['plan'] !== null)->values();

        return Inertia::render('Approvals/Inbox', [
            'items' => $items,
        ]);
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
