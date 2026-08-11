<?php

namespace App\Http\Controllers;

use App\Exports\EmployeeExport;
use App\Http\Controllers\Concerns\ReadsSort;
use App\Http\Resources\EmployeeResource;
use App\Jobs\GenerateFacecardZip;
use App\Models\CompetencyAssessment;
use App\Models\Employee;
use App\Models\FormalEducation;
use App\Models\JobStatus;
use App\Models\MatrixGradeConfig;
use App\Models\MovementTransaction;
use App\Models\PerformanceAppraisal;
use App\Models\ResultSummary;
use App\Models\TrainingCertification;
use App\Models\WorkExperience;
use App\Services\EmployeeScopeService;
use App\Services\IdpService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Data Access capability pairs: [self permission (IC / own record),
     * team permission (PM / direct reportees)]. Spread into the scope service.
     */
    private const FACECARD_VIEW = ['ic_view_facecard', 'pm_view_facecard'];

    private const FACECARD_DOWNLOAD = ['ic_download_facecard', 'pm_download_facecard'];

    private const IDP_VIEW = ['ic_view_idp', 'pm_view_idp'];

    private const IDP_DOWNLOAD = ['ic_download_idp', 'pm_download_idp'];

    public function __construct(
        private readonly EmployeeScopeService $scope,
        private readonly IdpService $idp,
    ) {}

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

        $base = $this->scope->accessibleQuery($user, ...self::FACECARD_VIEW);

        $employees = $this->filteredQuery(clone $base, $filters)
            ->orderBy($sort['key'], $sort['dir'])
            ->paginate((int) $request->integer('per_page', 10))
            ->withQueryString()
            ->through(fn ($employee) => (new EmployeeResource($employee))->resolve());

        return Inertia::render('Facecard/Index', [
            'employees' => $employees,
            'filters' => $filters,
            'sort' => $sort,
            'filterOptions' => $this->filterOptions(clone $base),
        ]);
    }

    /**
     * Export the current scoped + filtered employee list to Excel. Gated by the
     * facecard *download* capability (exporting the list is a download).
     */
    public function exportExcel(Request $request): BinaryFileResponse
    {
        $base = $this->scope->accessibleQuery($request->user(), ...self::FACECARD_DOWNLOAD);
        $query = $this->filteredQuery($base, $this->readFilters($request));

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
    private function filteredQuery(Builder $query, array $filters)
    {
        return $query
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

        abort_unless($this->scope->canAccess($user, (string) $employeeId, ...self::FACECARD_VIEW), 403);

        $employee = $this->scope->accessibleQuery($user, ...self::FACECARD_VIEW)
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
            // Data Access flags for this employee — drive the download buttons
            'canDownloadFacecard' => $this->scope->canAccess($user, (string) $employeeId, ...self::FACECARD_DOWNLOAD),
            'canViewIdp' => $this->scope->canAccess($user, (string) $employeeId, ...self::IDP_VIEW),
            'canDownloadIdp' => $this->scope->canAccess($user, (string) $employeeId, ...self::IDP_DOWNLOAD),
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
        abort_unless($this->scope->canAccess($user, $employeeId, ...self::FACECARD_DOWNLOAD), 403);

        $employee = $this->scope->accessibleQuery($user, ...self::FACECARD_DOWNLOAD)
            ->where('employee_id', $employeeId)->firstOrFail();

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
     * Kick off a background zip of the selected employees' facecard PDFs.
     */
    public function bulkDownload(Request $request): JsonResponse
    {
        $user = $request->user();

        // Selected employee ids from the list (checkboxes). Always intersected with
        // the user's visible set so a crafted request can't export outside scope.
        // Empty selection = export everyone visible (the "download all" behaviour).
        $requested = collect($request->input('employee_ids', []))
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique();

        $employeeIds = $this->scope->accessibleQuery($user, ...self::FACECARD_DOWNLOAD)
            ->when($requested->isNotEmpty(), fn ($q) => $q->whereIn('employee_id', $requested->all()))
            ->orderBy('fullname')
            ->pluck('employee_id')
            ->all();

        $status = JobStatus::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => 'pending',
            'progress' => 0,
        ]);

        GenerateFacecardZip::dispatch($employeeIds, $status->id);

        return response()->json(['job_id' => $status->id]);
    }

    /**
     * Poll a bulk-download job.
     */
    public function bulkStatus(Request $request, JobStatus $jobStatus): JsonResponse
    {
        abort_unless($jobStatus->user_id === $request->user()->id, 403);

        return response()->json([
            'status' => $jobStatus->status,
            'progress' => $jobStatus->progress,
            'ready' => $jobStatus->status === 'completed' && $jobStatus->file_name,
            'error' => $jobStatus->error_message,
        ]);
    }

    /**
     * Download the finished zip.
     */
    public function bulkFile(Request $request, JobStatus $jobStatus): StreamedResponse
    {
        abort_unless($jobStatus->user_id === $request->user()->id, 403);
        abort_unless($jobStatus->file_name, 404);

        $path = 'facecard-zips/'.$jobStatus->file_name;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'facecard_bulk.zip');
    }

    /**
     * Distinct filter values within the given (already scoped) employee set.
     */
    private function filterOptions(Builder $base): array
    {
        $pluckDistinct = fn (string $column) => (clone $base)
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
