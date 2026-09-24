<?php

namespace App\Http\Controllers;

use App\Exports\IdpExport;
use App\Exports\Templates\IdpPlanTemplateExport;
use App\Http\Controllers\Concerns\ReadsSort;
use App\Http\Requests\StoreIndividualDevelopmentPlanRequest;
use App\Http\Requests\UpdateIndividualDevelopmentPlanRequest;
use App\Http\Resources\EmployeeResource;
use App\Imports\SingleEmployeeDevelopmentPlanImport;
use App\Jobs\GenerateIdpZip;
use App\Models\BusinessUnit;
use App\Models\Competency;
use App\Models\DevelopmentModel;
use App\Models\Employee;
use App\Models\IdpApproval;
use App\Models\ImportLog;
use App\Models\IndividualDevelopmentPlan;
use App\Models\JobStatus;
use App\Models\ReviewTool;
use App\Models\User;
use App\Services\EmployeeScopeService;
use App\Services\Idp\IdpStageService;
use App\Services\IdpService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        private readonly IdpStageService $stage,
    ) {}

    /**
     * The team list — every employee this user may see EXCEPT themselves; each
     * row links to its IDP manage screen. The user's own plan has its own page
     * (`mine`), so the two menus never show the same person.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = $this->readFilters($request);
        $sort = $this->readSort($request, [
            'employee_id', 'fullname', 'group_company', 'job_level', 'designation_name',
        ], 'fullname');

        $base = $this->teamQuery($user, self::IDP_VIEW);

        // Which cycle the list is reporting on. The picker has no per-employee
        // count to show, so it asks for none.
        $activePackageId = $this->stage->currentPackage()?->id;
        $packages = $this->stage->packageOptions($activePackageId);
        $selectedPackageId = $this->stage->selectedPackageId(
            $packages,
            $request->integer('package') ?: null,
            $activePackageId,
        );

        $employees = $this->filteredQuery(clone $base, $filters)
            ->orderBy($sort['key'], $sort['dir'])
            ->paginate((int) $request->integer('per_page', 10))
            ->withQueryString()
            ->through(fn ($employee) => (new EmployeeResource($employee))->resolve());

        // Where each employee on THIS page stands in the chosen cycle, resolved
        // in two queries for the whole page rather than one pair per row.
        $employees->setCollection(
            $this->withCycleStatus($employees->getCollection(), $selectedPackageId),
        );

        return Inertia::render('Idp/Index', [
            'employees' => $employees,
            'filters' => $filters,
            'sort' => $sort,
            'packages' => $packages->all(),
            'selectedPackageId' => $selectedPackageId,
            'viewingActive' => $selectedPackageId !== null && $selectedPackageId === $activePackageId,
            'filterOptions' => $this->filterOptions(clone $base),
        ]);
    }

    /**
     * The signed-in user's own development plan — the manage screen, opened on
     * their own employee record with no list in between.
     *
     * A user with no employee record (a pure admin account), or one the IDP
     * data-access rules do not let see themselves, gets the page's empty state
     * rather than a 403: the menu item is shown to everyone.
     */
    public function mine(Request $request): Response
    {
        $user = $request->user();
        $employeeId = $user->employee_id;

        $employee = $employeeId
            ? $this->scope->accessibleQuery($user, ...self::IDP_VIEW)->where('employee_id', $employeeId)->first()
            : null;

        if (! $employee) {
            return Inertia::render('Idp/Mine', ['employee' => null]);
        }

        return Inertia::render('Idp/Mine', array_merge(
            ['employee' => new EmployeeResource($employee)],
            $this->idp->manageData(
                $employeeId,
                $user,
                canManage: true,
                packageId: $request->integer('package') ?: null,
            ),
        ));
    }

    /**
     * Everyone the user may see for a capability, minus the user themselves —
     * what "team" means on the list and its bulk download.
     *
     * @param  array{0: string, 1: string}  $capability
     */
    private function teamQuery(User $user, array $capability): Builder
    {
        return $this->scope->accessibleQuery($user, ...$capability)
            ->when($user->employee_id, fn ($q, $own) => $q->where('employee_id', '!=', $own));
    }

    /**
     * Attach each row's standing in one cycle: how many programs it holds and
     * where its planning approval is.
     *
     * The list is paginated, so this runs over the visible page only — two
     * queries in total, not two per employee.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withCycleStatus(Collection $rows, ?int $packageId): Collection
    {
        $ids = $rows->pluck('employee_id')->filter()->values();

        if ($packageId === null || $ids->isEmpty()) {
            return $rows->map(fn (array $row) => $row + ['cycle' => null]);
        }

        $modelIds = $this->stage->modelIdsFor($packageId);

        $plans = IndividualDevelopmentPlan::whereIn('employee_id', $ids)
            ->whereIn('development_model_id', $modelIds ?: [0])
            ->get(['id', 'employee_id', 'planning_approved_at'])
            ->groupBy('employee_id');

        // Ascending, so the last one written per employee wins — the latest
        // revision, which is the current approval for that cycle.
        $approvals = IdpApproval::planning()
            ->whereIn('employee_id', $ids)
            ->where('development_model_package_id', $packageId)
            ->orderBy('id')
            ->get()
            ->keyBy('employee_id');

        return $rows->map(function (array $row) use ($plans, $approvals) {
            $employeePlans = $plans->get($row['employee_id']) ?? collect();
            $approval = $approvals->get($row['employee_id']);

            return $row + ['cycle' => [
                'plans' => $employeePlans->count(),
                'status' => $this->stage->planningStatus($approval, $employeePlans),
                'current_level' => $approval?->status === 'pending' ? $approval->current_level : null,
                'total_levels' => $approval?->totalLevels(),
            ]];
        });
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

        // The business units come from the corporate master, not from the
        // employee set: a unit exists whether or not anyone is filed under it
        // yet. Every other option here stays derived from the visible rows.
        return [
            'businessUnits' => collect(BusinessUnit::names()),
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
        $inScope = $this->scope->canAccess($user, $employeeId, ...self::IDP_VIEW);

        // Approving happens on this screen (the Task Box only links here), and
        // an approval chain may name someone the visibility rules do not cover
        // — an L2 manager, or an override chain. Being named on any of this
        // employee's approvals opens the screen READ-ONLY: they can decide what
        // is theirs to decide, but not edit or submit the plan.
        $isApprover = ! $inScope && $this->isApproverFor($user, $employeeId);
        abort_unless($inScope || $isApprover, 403);

        $employee = $inScope
            ? $this->scope->accessibleQuery($user, ...self::IDP_VIEW)->where('employee_id', $employeeId)->firstOrFail()
            : Employee::where('employee_id', $employeeId)->firstOrFail();

        return Inertia::render('Idp/Manage', array_merge(
            [
                'employee' => new EmployeeResource($employee),
                'canManage' => $inScope,
            ],
            // The manage screen may edit + submit the IDP for approval — but
            // only while it is showing the ACTIVE cycle; the service decides
            // that from the package asked for.
            $this->idp->manageData(
                $employeeId,
                $user,
                canManage: $inScope,
                packageId: $request->integer('package') ?: null,
            ),
        ));
    }

    /** Whether the user sits on any layer of any of this employee's approvals. */
    private function isApproverFor(User $user, string $employeeId): bool
    {
        if (! $user->employee_id) {
            return false;
        }

        return IdpApproval::where('employee_id', $employeeId)
            ->whereHas('steps', fn ($q) => $q->where('approver_employee_id', $user->employee_id))
            ->exists();
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
        // The PDF covers the cycle the screen is showing, not always the active
        // one — the download button carries the package it was pressed on.
        $data = $this->idp->manageData($employeeId, packageId: $request->integer('package') ?: null);

        $pdf = Pdf::loadView('pdf.idp', [
            'employee' => $employee,
            'developmentModels' => $data['developmentModels'],
            // The document says which cycle it covers and where its plan stands.
            'planning' => $data['planning'],
        ]);

        return $pdf->download('idp_'.Str::slug($employee->fullname).'.pdf');
    }

    /**
     * Download an employee's IDP as an Excel file.
     */
    public function export(Request $request, string $employeeId): BinaryFileResponse
    {
        abort_unless($this->scope->canAccess($request->user(), $employeeId, ...self::IDP_DOWNLOAD), 403);

        // Same rule as the PDF: the file covers the cycle the screen was on.
        return Excel::download(
            new IdpExport($employeeId, $request->integer('package') ?: null),
            'idp_'.$employeeId.'.xlsx',
        );
    }

    /**
     * Download the pre-built single-employee IDP upload template.
     */
    public function downloadTemplate(Request $request, string $employeeId): BinaryFileResponse
    {
        abort_unless($this->scope->canView($request->user(), $employeeId), 403);

        $employee = $this->scope->query($request->user())->where('employee_id', $employeeId)->first();
        $safeName = $employee ? Str::slug($employee->fullname ?? $employeeId, '_') : $employeeId;

        // Generated, not a file on disk: the columns then always match what the
        // importer reads, and the reference tabs always show the master data as
        // it stands rather than as it stood when someone last exported it.
        return Excel::download(
            app(IdpPlanTemplateExport::class),
            "IDP_Template_{$employeeId}_{$safeName}.xlsx",
        );
    }

    /**
     * Download the IDP master-data reference (instructions + valid values) as PDF.
     */
    public function downloadMasterPdf(): HttpResponse
    {
        // Sort competencies by the legacy S-I-G-A-P priority, then alphabetically.
        $priority = ['S' => 1, 'I' => 2, 'G' => 3, 'A' => 4, 'P' => 5];
        $competencyNames = Competency::with('developmentPrograms.developmentModel')->get()
            ->sortBy(fn ($item) => [$priority[strtoupper(substr($item->name_en, 0, 1))] ?? 99, $item->name_en])
            ->values();

        $competencyGroupedMap = [];
        foreach ($competencyNames as $comp) {
            $competencyGroupedMap[$comp->id] = $comp->developmentPrograms
                ->sortBy('name_en')
                ->groupBy(fn ($item) => $item->developmentModel
                    ? $item->developmentModel->name.' ('.$item->developmentModel->percentage.'%)'
                    : 'Uncategorized')
                ->sortBy(fn ($group, $key) => $key === 'Uncategorized'
                    ? 999
                    : ($group->first()->developmentModel->percentage ?? 999));
        }

        $reviewTools = ReviewTool::orderBy('name_en')->get();

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

        // A plan can only be written into the ACTIVE cycle, so that is the only
        // cycle an upload may target — whichever one the screen was showing.
        $package = $this->stage->currentPackage();

        if (! $package) {
            return back()->with('error', 'There is no active development model package to import into.');
        }

        // An import adds plans, so it is held to the same freeze the add form is:
        // the set must not be with an approver.
        try {
            $this->stage->assertPlansEditable($employeeId, $package->id);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        $path = $request->file('idp_file')->store('imports', 'local');

        $import = new SingleEmployeeDevelopmentPlanImport(
            $employeeId,
            $this->stage->modelIdsFor($package->id),
        );

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

        // Imported rows change the set the same way the add form does.
        if ($imported > 0) {
            $this->stage->withdrawPlanningApproval($employeeId, $package->id);
        }

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

        $employeeIds = $this->teamQuery($user, self::IDP_DOWNLOAD)
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

        // The zip covers the cycle the list was showing, not always the active
        // one — same rule as the per-employee PDF button.
        GenerateIdpZip::dispatch($employeeIds, $status->id, $request->integer('package') ?: null);

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

    /**
     * Add a plan. Refused while the package's planning approval is in flight —
     * approvers must not have the set change under them mid-review. A plan added
     * to an already-approved set is simply unapproved, which puts the set into
     * `revision` until it is submitted again.
     */
    public function store(StoreIndividualDevelopmentPlanRequest $request): RedirectResponse
    {
        $user = $request->user();
        $employeeId = $request->validated('employee_id');

        abort_unless($this->scope->canView($user, $employeeId), 403);

        $data = $request->validated();

        $packageId = DevelopmentModel::withTrashed()
            ->whereKey($data['development_model_id'])
            ->value('development_model_package_id');

        try {
            $this->stage->assertPlansEditable($employeeId, $packageId);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        IndividualDevelopmentPlan::create($data);

        // A new program changes the set that was signed off, so the whole plan
        // returns to "not approved" and has to be submitted again.
        $withdrawn = $this->stage->withdrawPlanningApproval($employeeId, $packageId);

        return back()->with('success', $withdrawn > 0
            ? 'Development plan added. The plan needs approval again.'
            : 'Development plan added successfully.');
    }

    /**
     * Edit a plan's PLANNING fields. Changing any of them withdraws that row's
     * planning sign-off, so the set has to be approved again before the row's
     * result can be filed.
     */
    public function update(UpdateIndividualDevelopmentPlanRequest $request, IndividualDevelopmentPlan $idp): RedirectResponse
    {
        abort_unless($this->scope->canView($request->user(), $idp->employee_id), 403);

        // Read before the save: a change of development model can move the row
        // to another package, and BOTH sets then stop describing what was
        // approved.
        $packageBefore = $this->stage->packageIdFor($idp);

        try {
            $this->stage->assertPlansEditable($idp->employee_id, $packageBefore);
            $this->stage->assertResultNotSettled($idp);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        $idp->update($request->validated());

        if (! $idp->wasChanged(IndividualDevelopmentPlan::PLANNING_FIELDS)) {
            return back()->with('success', 'Development plan updated successfully.');
        }

        $withdrawn = 0;

        foreach (array_unique(array_filter([$packageBefore, $this->stage->packageIdFor($idp)])) as $packageId) {
            $withdrawn += $this->stage->withdrawPlanningApproval($idp->employee_id, $packageId);
        }

        return back()->with('success', $withdrawn > 0
            ? 'Development plan updated. The plan needs approval again.'
            : 'Development plan updated successfully.');
    }

    public function destroy(Request $request, IndividualDevelopmentPlan $idp): RedirectResponse
    {
        abort_unless($this->scope->canView($request->user(), $idp->employee_id), 403);

        $packageId = $this->stage->packageIdFor($idp);

        try {
            $this->stage->assertPlansEditable($idp->employee_id, $packageId);
            $this->stage->assertResultNotSettled($idp);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        $idp->delete();

        // Removing a program changes the set that was signed off, exactly as
        // adding or editing one does.
        $withdrawn = $this->stage->withdrawPlanningApproval($idp->employee_id, $packageId);

        return back()->with('success', $withdrawn > 0
            ? 'Development plan deleted. The plan needs approval again.'
            : 'Development plan deleted successfully.');
    }
}
