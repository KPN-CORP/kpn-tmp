<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmployeeResource;
use App\Models\CompetencyAssessment;
use App\Models\FormalEducation;
use App\Models\MatrixGradeConfig;
use App\Models\PerformanceAppraisal;
use App\Models\ResultSummary;
use App\Models\TrainingCertification;
use App\Models\WorkExperience;
use App\Services\EmployeeScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeScopeService $scope)
    {
    }

    /**
     * Paginated, filtered facecard list — scoped to what the user may see.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'business_unit' => $request->string('business_unit')->value(),
            'job_level' => $request->string('job_level')->value(),
            'designation' => $request->string('designation')->value(),
        ];

        $employees = $this->scope->query($user)
            ->when($filters['search'], fn ($q, $term) => $q->where(function ($sub) use ($term) {
                $sub->where('fullname', 'like', "%{$term}%")
                    ->orWhere('employee_id', 'like', "%{$term}%");
            }))
            ->when($filters['business_unit'], fn ($q, $v) => $q->where('group_company', $v))
            ->when($filters['job_level'], fn ($q, $v) => $q->where('job_level', $v))
            ->when($filters['designation'], fn ($q, $v) => $q->where('designation_name', $v))
            ->orderBy('fullname')
            ->paginate((int) $request->integer('per_page', 15))
            ->withQueryString()
            ->through(fn ($employee) => (new EmployeeResource($employee))->resolve());

        return Inertia::render('Facecard/Index', [
            'employees' => $employees,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($user),
        ]);
    }

    /**
     * Employee facecard / profile detail.
     */
    public function show(Request $request, ?string $employeeId = null): Response
    {
        $user = $request->user();
        $employeeId ??= $user->employee_id;

        abort_unless($this->scope->canView($user, (string) $employeeId), 403);

        $employee = $this->scope->query($user)
            ->where('employee_id', $employeeId)
            ->firstOrFail();

        return Inertia::render('Facecard/Profile', [
            'employee' => new EmployeeResource($employee),
            'formalEducations' => $this->safeGet(fn () => FormalEducation::where('employee_id', $employeeId)
                ->orderByDesc('from_date')->get()
                ->map(fn ($e) => [
                    'from_date' => $e->from_date,
                    'to_date' => $e->to_date,
                    'degree' => $e->degree,
                    'institution' => $e->institution,
                    'major' => $e->major,
                    'gpa_percentage' => $e->gpa_percentage,
                ])),
            'workExperiences' => $this->safeGet(fn () => WorkExperience::where('employee_id', $employeeId)
                ->orderByDesc('from_date')->get()
                ->map(fn ($w) => [
                    'from_date' => $w->from_date,
                    'to_date' => $w->to_date,
                    'company' => $w->previous_company_name,
                    'position' => $w->jabatan_akhir,
                ])),
            'trainings' => $this->safeGet(fn () => TrainingCertification::where('employee_id', $employeeId)
                ->orderByDesc('certification_completion_date')->get()
                ->map(fn ($t) => [
                    'issue_date' => $t->certification_issue_date,
                    'completion_date' => $t->certification_completion_date,
                    'name' => $t->certification_name,
                    'organizer' => $t->organizer,
                ])),
            'appraisals' => $this->safeGet(fn () => PerformanceAppraisal::where('employee_id', $employeeId)
                ->orderByDesc('appraisal_year')->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'appraisal_year' => (int) $a->appraisal_year,
                    'grade' => $a->grade,
                    'potential' => $a->potential,
                    'talent_box' => $a->talent_box,
                ])),
            'competencyAssessments' => CompetencyAssessment::where('employee_id', $employeeId)
                ->orderByDesc('period')->get(),
            'resultSummary' => ResultSummary::where('employee_id', $employeeId)->first(),
            'matrixGradeConfigs' => MatrixGradeConfig::orderBy('period')->orderBy('grade_level')->get(),
            'canInputNineBox' => $user->can('input_year_on_year'),
            'canInputCompetency' => $user->can('input_competency_assessment'),
            'canInputSuccession' => $user->can('input_successor_position'),
        ]);
    }

    /**
     * Distinct filter values within the user's visible employee set.
     */
    private function filterOptions($user): array
    {
        $pluckDistinct = fn (string $column) => $this->scope->query($user)
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->values();

        return [
            'businessUnits' => $pluckDistinct('group_company'),
            'jobLevels' => $pluckDistinct('job_level'),
            'designations' => $pluckDistinct('designation_name'),
        ];
    }

    /**
     * Read kpncorp sub-data defensively — a missing table/column or an
     * unavailable connection yields an empty collection rather than a 500.
     */
    private function safeGet(callable $callback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            return collect();
        }
    }
}
