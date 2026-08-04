<?php

namespace App\Http\Controllers;

use App\Models\PerformanceAppraisal;
use App\Services\EmployeeScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Year-on-year 9-box mapping. `grade` (performance) comes from the corporate
 * appraisal; this app captures `potential` and the resulting `talent_box`.
 * PerformanceAppraisal lives on the kpncorp connection (see the model).
 */
class PerformanceAppraisalController extends Controller
{
    public function __construct(private readonly EmployeeScopeService $scope)
    {
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeEmployee($request, $request->input('employee_id'));

        $validated = $request->validate([
            'employee_id' => ['required', 'string'],
            'appraisal_year' => ['required', 'integer', 'digits:4'],
            'potential' => ['required', 'string', 'in:High,Medium,Low'],
            'talent_box' => ['nullable', 'string'],
        ]);

        $exists = PerformanceAppraisal::where('employee_id', $validated['employee_id'])
            ->where('appraisal_year', $validated['appraisal_year'])
            ->exists();

        if ($exists) {
            return back()->with('error', "Year {$validated['appraisal_year']} already exists for this employee.");
        }

        PerformanceAppraisal::create([
            'employee_id' => $validated['employee_id'],
            'appraisal_year' => $validated['appraisal_year'],
            'grade' => null,
            'potential' => $validated['potential'],
            'talent_box' => $validated['talent_box'] ?? null,
        ]);

        return back()->with('success', 'Year-on-Year 9-Box data added successfully.');
    }

    public function update(Request $request, PerformanceAppraisal $appraisal): RedirectResponse
    {
        $this->authorizeEmployee($request, $appraisal->employee_id);

        $validated = $request->validate([
            'potential' => ['nullable', 'string', 'in:High,Medium,Low'],
            'talent_box' => ['nullable', 'string'],
        ]);

        $appraisal->update($validated);

        return back()->with('success', "9-Box data for {$appraisal->appraisal_year} has been updated.");
    }

    public function destroy(Request $request): RedirectResponse
    {
        $employeeId = $request->input('employee_id');
        $this->authorizeEmployee($request, $employeeId);

        $validated = $request->validate([
            'employee_id' => ['required', 'string'],
            'appraisal_year' => ['required', 'integer'],
        ]);

        $record = PerformanceAppraisal::where('employee_id', $validated['employee_id'])
            ->where('appraisal_year', $validated['appraisal_year'])
            ->firstOrFail();

        // A row backed by a corporate performance grade is only reset (keep the
        // grade); a row this app created outright is deleted.
        if ($record->grade) {
            $record->update(['potential' => null, 'talent_box' => null]);
            $message = "Potential & Talent Box for {$validated['appraisal_year']} has been reset.";
        } else {
            $record->delete();
            $message = "Year {$validated['appraisal_year']} has been deleted.";
        }

        return back()->with('success', $message);
    }

    private function authorizeEmployee(Request $request, ?string $employeeId): void
    {
        abort_unless(
            $employeeId && $this->scope->canView($request->user(), $employeeId),
            403,
        );
    }
}
