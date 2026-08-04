<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIndividualDevelopmentPlanRequest;
use App\Http\Requests\UpdateIndividualDevelopmentPlanRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\IndividualDevelopmentPlan;
use App\Exports\IdpExport;
use App\Http\Controllers\Concerns\ReadsSort;
use App\Jobs\GenerateIdpZip;
use App\Models\JobStatus;
use App\Services\EmployeeScopeService;
use App\Services\IdpService;
use Barryvdh\DomPDF\Facade\Pdf;
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
        $sort = $this->readSort($request, [
            'employee_id', 'fullname', 'group_company', 'designation_name',
        ], 'fullname');

        $employees = $this->scope->query($user)
            ->when($search, fn ($q, $term) => $q->where(function ($sub) use ($term) {
                $sub->where('fullname', 'like', "%{$term}%")
                    ->orWhere('employee_id', 'like', "%{$term}%");
            }))
            ->orderBy($sort['key'], $sort['dir'])
            ->paginate((int) $request->integer('per_page', 10))
            ->withQueryString()
            ->through(fn ($employee) => (new EmployeeResource($employee))->resolve());

        return Inertia::render('Idp/Index', [
            'employees' => $employees,
            'filters' => ['search' => $search],
            'sort' => $sort,
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

    /**
     * Download an employee's IDP as a PDF.
     */
    public function downloadPdf(Request $request, string $employeeId): HttpResponse
    {
        $user = $request->user();
        abort_unless($this->scope->canView($user, $employeeId), 403);

        $employee = $this->scope->query($user)->where('employee_id', $employeeId)->firstOrFail();
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
        abort_unless($this->scope->canView($request->user(), $employeeId), 403);

        return Excel::download(new IdpExport($employeeId), 'idp_'.$employeeId.'.xlsx');
    }

    /**
     * Kick off a background zip of every visible employee's IDP PDF.
     */
    public function bulkDownload(Request $request): JsonResponse
    {
        $user = $request->user();

        $employeeIds = $this->scope->query($user)
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
