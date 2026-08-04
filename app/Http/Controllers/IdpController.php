<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIndividualDevelopmentPlanRequest;
use App\Http\Requests\UpdateIndividualDevelopmentPlanRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\IndividualDevelopmentPlan;
use App\Services\EmployeeScopeService;
use App\Services\IdpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IdpController extends Controller
{
    public function __construct(
        private readonly EmployeeScopeService $scope,
        private readonly IdpService $idp,
    ) {
    }

    /**
     * Employee list — each row links to its IDP manage screen.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $search = $request->string('search')->trim()->value();

        $employees = $this->scope->query($user)
            ->when($search, fn ($q, $term) => $q->where(function ($sub) use ($term) {
                $sub->where('fullname', 'like', "%{$term}%")
                    ->orWhere('employee_id', 'like', "%{$term}%");
            }))
            ->orderBy('fullname')
            ->paginate((int) $request->integer('per_page', 15))
            ->withQueryString()
            ->through(fn ($employee) => (new EmployeeResource($employee))->resolve());

        return Inertia::render('Idp/Index', [
            'employees' => $employees,
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * Manage an employee's development plans.
     */
    public function show(Request $request, string $employeeId): Response
    {
        $user = $request->user();
        abort_unless($this->scope->canView($user, $employeeId), 403);

        $employee = $this->scope->query($user)
            ->where('employee_id', $employeeId)
            ->firstOrFail();

        return Inertia::render('Idp/Manage', array_merge(
            ['employee' => new EmployeeResource($employee)],
            $this->idp->manageData($employeeId),
        ));
    }

    public function store(StoreIndividualDevelopmentPlanRequest $request): RedirectResponse
    {
        $user = $request->user();
        $employeeId = $request->validated('employee_id');

        abort_unless($this->scope->canView($user, $employeeId), 403);

        IndividualDevelopmentPlan::create($request->validated());

        return back()->with('success', 'Development plan added successfully.');
    }

    public function update(UpdateIndividualDevelopmentPlanRequest $request, IndividualDevelopmentPlan $idp): RedirectResponse
    {
        abort_unless($this->scope->canView($request->user(), $idp->employee_id), 403);

        $idp->update($request->validated());

        return back()->with('success', 'Development plan updated successfully.');
    }

    public function destroy(Request $request, IndividualDevelopmentPlan $idp): RedirectResponse
    {
        abort_unless($this->scope->canView($request->user(), $idp->employee_id), 403);

        $idp->delete();

        return back()->with('success', 'Development plan deleted successfully.');
    }
}
