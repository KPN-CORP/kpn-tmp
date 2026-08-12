<?php

namespace App\Http\Controllers;

use App\Exports\IdpExport;
use App\Http\Controllers\Concerns\ReadsSort;
use App\Http\Requests\StoreIndividualDevelopmentPlanRequest;
use App\Http\Requests\UpdateIndividualDevelopmentPlanRequest;
use App\Http\Resources\EmployeeResource;
use App\Imports\SingleEmployeeDevelopmentPlanImport;
use App\Jobs\GenerateIdpZip;
use App\Models\DevelopmentPlanMaster;
use App\Models\ImportLog;
use App\Models\IndividualDevelopmentPlan;
use App\Models\JobStatus;
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

class IdpController extends Controller
{
    use ReadsSort;

    /** Data Access capability pairs: [self permission (IC), team permission (PM)]. */
    private const IDP_VIEW = ['ic_view_idp', 'pm_view_idp'];

    private const IDP_DOWNLOAD = ['ic_download_idp', 'pm_download_idp'];

    public function __construct(
        private readonly EmployeeScopeService $scope,
        private readonly IdpService $idp,
    ) {}

    /**
     * Employee list — each row links to its IDP manage screen.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = $this->readFilters($request);
        $sort = $this->readSort($request, [
            'employee_id', 'fullname', 'group_company', 'job_level', 'designation_name',
        ], 'fullname');

        $base = $this->scope->accessibleQuery($user, ...self::IDP_VIEW);

        $employees = $this->filteredQuery(clone $base, $filters)
            ->orderBy($sort['key'], $sort['dir'])
            ->paginate((int) $request->integer('per_page', 10))
            ->withQueryString()
            ->through(fn ($employee) => (new EmployeeResource($employee))->resolve());

        return Inertia::render('Idp/Index', [
            'employees' => $employees,
            'filters' => $filters,
            'sort' => $sort,
            'filterOptions' => $this->filterOptions(clone $base),
        ]);
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
     * Manage an employee's development plans.
     */
    public function show(Request $request, string $employeeId): Response
    {
        $user = $request->user();
        abort_unless($this->scope->canAccess($user, $employeeId, ...self::IDP_VIEW), 403);

        $employee = $this->scope->accessibleQuery($user, ...self::IDP_VIEW)
            ->where('employee_id', $employeeId)
            ->firstOrFail();

        return Inertia::render('Idp/Manage', array_merge(
            ['employee' => new EmployeeResource($employee)],
            // The manage screen may edit + submit the IDP for approval.
            $this->idp->manageData($employeeId, $user, canManage: true),
        ));
    }

    /**
     * Download an employee's IDP as a PDF.
     */
    public function downloadPdf(Request $request, string $employeeId): HttpResponse
    {
        $user = $request->user();
        abort_unless($this->scope->canAccess($user, $employeeId, ...self::IDP_DOWNLOAD), 403);

        $employee = $this->scope->accessibleQuery($user, ...self::IDP_DOWNLOAD)
            ->where('employee_id', $employeeId)->firstOrFail();
        $data = $this->idp->manageData($employeeId);

        $pdf = Pdf::loadView('pdf.idp', [
            'employee' => $employee,
            'developmentModels' => $data['developmentModels'],
        ]);

        return $pdf->download('idp_'.Str::slug($employee->fullname).'.pdf');
    }

    /**
     * Download an employee's IDP as an Excel file.
     */
    public function export(Request $request, string $employeeId): BinaryFileResponse
    {
        abort_unless($this->scope->canAccess($request->user(), $employeeId, ...self::IDP_DOWNLOAD), 403);

        return Excel::download(new IdpExport($employeeId), 'idp_'.$employeeId.'.xlsx');
    }

    /**
     * Download the pre-built single-employee IDP upload template.
     */
    public function downloadTemplate(Request $request, string $employeeId): BinaryFileResponse
    {
        abort_unless($this->scope->canView($request->user(), $employeeId), 403);

        $path = public_path('templates/template_idp_single.xlsx');
        abort_unless(file_exists($path), 404, 'Template file not found.');

        $employee = $this->scope->query($request->user())->where('employee_id', $employeeId)->first();
        $safeName = $employee ? Str::slug($employee->fullname ?? $employeeId, '_') : $employeeId;

        return response()->download($path, "IDP_Template_{$employeeId}_{$safeName}.xlsx");
    }

    /**
     * Download the IDP master-data reference (instructions + valid values) as PDF.
     */
    public function downloadMasterPdf(): HttpResponse
    {
        // Sort competencies by the legacy S-I-G-A-P priority, then alphabetically.
        $priority = ['S' => 1, 'I' => 2, 'G' => 3, 'A' => 4, 'P' => 5];
        $competencyNames = DevelopmentPlanMaster::where('type', 'competency_name')->get()
            ->sortBy(fn ($item) => [$priority[strtoupper(substr($item->value, 0, 1))] ?? 99, $item->value])
            ->values();

        $programsById = DevelopmentPlanMaster::where('type', 'development_program')
            ->with('developmentModel')
            ->orderBy('value')
            ->get()
            ->keyBy('id');

        $competencyGroupedMap = [];
        foreach ($competencyNames as $comp) {
            $programs = collect($comp->related_program ?? [])
                ->map(fn ($pid) => $programsById->get($pid) ?? $programsById->get((int) $pid))
                ->filter();

            $competencyGroupedMap[$comp->id] = $programs
                ->groupBy(fn ($item) => $item->developmentModel
                    ? $item->developmentModel->name.' ('.$item->developmentModel->percentage.'%)'
                    : 'Uncategorized')
                ->sortBy(fn ($group, $key) => $key === 'Uncategorized'
                    ? 999
                    : ($group->first()->developmentModel->percentage ?? 999));
        }

        $reviewTools = DevelopmentPlanMaster::where('type', 'review_tools')->orderBy('value')->get();

        $pdf = Pdf::loadView('pdf.idp_master_list', [
            'competencyNames' => $competencyNames,
            'competencyGroupedMap' => $competencyGroupedMap,
            'reviewTools' => $reviewTools,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('IDP_Master_Data_'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * Upload an Excel file that adds new IDP plans for a single employee.
     */
    public function import(Request $request, string $employeeId): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->scope->canView($user, $employeeId), 403);

        $request->validate([
            'idp_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        $path = $request->file('idp_file')->store('imports', 'local');

        $import = new SingleEmployeeDevelopmentPlanImport($employeeId);

        try {
            Excel::import($import, Storage::disk('local')->path($path));
        } catch (\Throwable $e) {
            ImportLog::create([
                'user_id' => $user->id,
                'data_type' => 'idp',
                'import_date' => now(),
                'status' => 'Failed',
                'result' => 'Import error: '.$e->getMessage(),
                'original_file_path' => $path,
            ]);

            return back()->with('error', 'Import failed: '.$e->getMessage());
        }

        $imported = $import->imported();
        $errors = $import->errors();
        $failed = count($errors);

        $summary = "{$imported} plan(s) imported".($failed ? ", {$failed} row(s) skipped." : '.');
        $detail = $failed ? ' '.implode(' ', array_slice($errors, 0, 15)) : '';

        ImportLog::create([
            'user_id' => $user->id,
            'data_type' => 'idp',
            'import_date' => now(),
            'status' => $failed === 0 ? 'Success' : ($imported > 0 ? 'Partial Success' : 'Failed'),
            'result' => $summary.$detail,
            'original_file_path' => $path,
        ]);

        return back()->with($failed === 0 ? 'success' : 'error', $summary.$detail);
    }

    /**
     * Kick off a background zip of every visible employee's IDP PDF.
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

        $employeeIds = $this->scope->accessibleQuery($user, ...self::IDP_DOWNLOAD)
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

        GenerateIdpZip::dispatch($employeeIds, $status->id);

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

        $path = 'idp-zips/'.$jobStatus->file_name;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'idp_bulk.zip');
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
