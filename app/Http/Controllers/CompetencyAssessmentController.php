<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompetencyAssessmentRequest;
use App\Models\CompetencyAssessment;
use App\Services\EmployeeScopeService;
use App\Services\MatrixGradeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

class CompetencyAssessmentController extends Controller
{
    public function __construct(
        private readonly EmployeeScopeService $scope,
        private readonly MatrixGradeService $matrix,
    ) {
    }

    /**
     * Create or update the assessment for an employee + period (year).
     */
    public function store(StoreCompetencyAssessmentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        abort_unless($this->scope->canView($request->user(), $data['employee_id']), 403);

        $period = Carbon::parse($data['assessment_date'])->year;

        // Matrix (target) grade is derived server-side from the scores.
        $data['period'] = $period;
        $data['matrix_grade'] = $this->matrix->calculate($request->scores(), $period);

        CompetencyAssessment::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'period' => $period],
            $data,
        );

        return back()->with('success', 'Competency assessment saved successfully.');
    }
}
