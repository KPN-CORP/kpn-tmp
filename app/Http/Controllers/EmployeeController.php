<?php

namespace App\Http\Controllers;

use App\Exports\EmployeeExport;
use App\Http\Controllers\Concerns\ReadsSort;
use App\Http\Resources\EmployeeResource;
use App\Models\CompetencyAssessment;
use App\Models\Employee;
use App\Models\FormalEducation;
use App\Models\MatrixGradeConfig;
use App\Models\MovementTransaction;
use App\Models\PerformanceAppraisal;
use App\Models\ResultSummary;
use App\Models\TrainingCertification;
use App\Models\WorkExperience;
use App\Services\EmployeeScopeService;
use App\Services\IdpService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class EmployeeController extends Controller
{
    use ReadsSort;

    /**
     * Movement attributes surfaced on the Internal Movement table (mirrors facecard).
     *
     * @var list<string>
     */
    private const MOVEMENT_ATTRIBUTES = [
        'Office Location Change',
        'Company Name Change',
        'Employee Type Change',
        'Designation Change',
        'Job Level Change',
    ];

    public function __construct(
        private readonly EmployeeScopeService $scope,
        private readonly IdpService $idp,
    ) {
    }

    /**
     * Paginated, filtered facecard list — scoped to what the user may see.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = $this->readFilters($request);
        $sort = $this->readSort($request, [
            'employee_id', 'fullname', 'group_company', 'job_level', 'designation_name',
        ], 'fullname');

        $employees = $this->filteredQuery($user, $filters)
            ->orderBy($sort['key'], $sort['dir'])
            ->paginate((int) $request->integer('per_page', 10))
            ->withQueryString()
            ->through(fn ($employee) => (new EmployeeResource($employee))->resolve());

        return Inertia::render('Facecard/Index', [
            'employees' => $employees,
            'filters' => $filters,
            'sort' => $sort,
            'filterOptions' => $this->filterOptions($user),
        ]);
    }

    /**
     * Export the current scoped + filtered employee list to Excel.
     */
    public function exportExcel(Request $request): BinaryFileResponse
    {
        $query = $this->filteredQuery($request->user(), $this->readFilters($request));

        return Excel::download(new EmployeeExport($query), 'facecard_'.now()->format('Ymd_His').'.xlsx');
    }

    /**
     * @return array<string, string>
     */
    private function readFilters(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'business_unit' => $request->string('business_unit')->value(),
            'job_level' => $request->string('job_level')->value(),
            'designation' => $request->string('designation')->value(),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function filteredQuery($user, array $filters)
    {
        return $this->scope->query($user)
            ->when($filters['search'], fn ($q, $term) => $q->where(function ($sub) use ($term) {
                $sub->where('fullname', 'like', "%{$term}%")
                    ->orWhere('employee_id', 'like', "%{$term}%");
            }))
            ->when($filters['business_unit'], fn ($q, $v) => $q->where('group_company', $v))
            ->when($filters['job_level'], fn ($q, $v) => $q->where('job_level', $v))
            ->when($filters['designation'], fn ($q, $v) => $q->where('designation_name', $v));
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

        $resultSummary = ResultSummary::where('employee_id', $employeeId)->first();

        return Inertia::render('Facecard/Profile', array_merge($this->idp->manageData($employeeId), [
            'employee' => new EmployeeResource($employee),
            'photoUrl' => $this->photoUrl($employeeId),
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
            'resultSummary' => $resultSummary,
            'successorLabel' => $this->successorLabel($resultSummary),
            'movements' => $this->movements($employeeId),
            'movementAttributes' => self::MOVEMENT_ATTRIBUTES,
            'matrixGradeConfigs' => MatrixGradeConfig::orderBy('period')->orderBy('grade_level')->get(),
            'canInputNineBox' => $user->can('input_year_on_year'),
            'canInputCompetency' => $user->can('input_competency_assessment'),
            'canInputSuccession' => $user->can('input_successor_position'),
        ]));
    }

    /**
     * Internal-movement rows for the profile, read defensively from kpncorp.
     * Type is derived from the promotion/demotion flags, mirroring facecard.
     */
    private function movements(string $employeeId)
    {
        return $this->safeGet(fn () => MovementTransaction::where('employee_id', $employeeId)
            ->whereIn('attribute', self::MOVEMENT_ATTRIBUTES)
            ->orderByDesc('effective_from')->get()
            ->map(fn ($m) => [
                'effective_from' => $m->effective_from,
                'effective_to' => $m->effective_to_date,
                'type' => $m->is_promotion === 'Yes'
                    ? 'Promotion'
                    : ($m->is_demotion === 'Yes' ? 'Demotion' : 'Transfer'),
                'detail' => $m->attribute,
                'from' => $m->from,
                'to' => $m->to,
                'status' => $m->array_status,
            ])->values());
    }

    /**
     * Resolve the succession "successor to position" code into a readable label.
     */
    private function successorLabel(?ResultSummary $resultSummary): ?string
    {
        $code = $resultSummary?->successor_to_position;
        if (! $code) {
            return null;
        }

        $label = $this->safeGet(function () use ($code) {
            $designation = Employee::where('designation_code', $code)->first();

            return $designation && $designation->designation_name
                ? $designation->designation_name.' ('.$designation->designation_code.')'
                : $code;
        });

        return is_string($label) ? $label : $code;
    }

    /**
     * Public URL of the employee photo (stored on the public disk, keyed by
     * employee_id), with a cache-buster; null when no photo has been uploaded.
     */
    private function photoUrl(string $employeeId): ?string
    {
        $disk = Storage::disk('public');

        foreach ($disk->files('employee-photos') as $file) {
            if (pathinfo($file, PATHINFO_FILENAME) === $employeeId) {
                return $disk->url($file).'?t='.$disk->lastModified($file);
            }
        }

        return null;
    }

    /**
     * Upload / replace the employee's profile photo.
     */
    public function updatePhoto(Request $request, string $employeeId): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->scope->canView($user, (string) $employeeId), 403);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:2048'],
        ]);

        $disk = Storage::disk('public');
        $this->deletePhotoFiles($employeeId, $disk);

        $ext = strtolower($request->file('photo')->getClientOriginalExtension() ?: 'jpg');
        $request->file('photo')->storeAs('employee-photos', $employeeId.'.'.$ext, 'public');

        return back();
    }

    /**
     * Remove the employee's profile photo.
     */
    public function deletePhoto(Request $request, string $employeeId): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->scope->canView($user, (string) $employeeId), 403);

        $this->deletePhotoFiles($employeeId, Storage::disk('public'));

        return back();
    }

    /**
     * Delete any stored photo file(s) for the employee, whatever the extension.
     */
    private function deletePhotoFiles(string $employeeId, $disk): void
    {
        foreach ($disk->files('employee-photos') as $file) {
            if (pathinfo($file, PATHINFO_FILENAME) === $employeeId) {
                $disk->delete($file);
            }
        }
    }

    /**
     * Single facecard PDF for one employee.
     */
    public function downloadPdf(Request $request, string $employeeId): HttpResponse
    {
        $user = $request->user();
        abort_unless($this->scope->canView($user, $employeeId), 403);

        $employee = $this->scope->query($user)->where('employee_id', $employeeId)->firstOrFail();

        $pdf = Pdf::loadView('pdf.facecard', [
            'employee' => $employee,
            'competencyAssessments' => CompetencyAssessment::where('employee_id', $employeeId)
                ->orderByDesc('period')->get(),
            'appraisals' => $this->safeGet(fn () => PerformanceAppraisal::where('employee_id', $employeeId)
                ->orderByDesc('appraisal_year')->get()),
            'resultSummary' => ResultSummary::where('employee_id', $employeeId)->first(),
        ]);

        return $pdf->download('facecard_'.Str::slug($employee->fullname).'.pdf');
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
