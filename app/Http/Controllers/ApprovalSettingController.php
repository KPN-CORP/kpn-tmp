<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsSort;
use App\Http\Requests\UpdateApprovalSuperiorRequest;
use App\Imports\ApprovalLayerImport;
use App\Models\ApprovalSuperior;
use App\Models\ApprovalSuperiorHistory;
use App\Models\Employee;
use App\Services\EmployeeScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Approval Layers — per-employee superior chains (an ordered, variable-length
 * list of approvers). When unset, a chain defaults to the corporate manager_l1
 * / manager_l2; admins can override it and add/remove layers. Every save is
 * audited. The approve/reject runtime that consumes these chains lands later.
 */
class ApprovalSettingController extends Controller
{
    use ReadsSort;

    public function __construct(private readonly EmployeeScopeService $scope) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $this->readFilters($request);
        $sort = $this->readSort(
            $request,
            ['employee_id', 'fullname', 'company_name', 'office_area', 'group_company'],
            'fullname',
        );

        $employees = $this->filteredQuery($user, $filters)
            ->orderBy($sort['key'], $sort['dir'])
            ->paginate((int) $request->integer('per_page', 25))
            ->withQueryString();

        // Load saved overrides for just this page's employees.
        $overrides = ApprovalSuperior::whereIn('employee_id', $employees->pluck('employee_id'))
            ->get()
            ->keyBy('employee_id');

        // Resolve every referenced approver id (effective L1..L5) to a name in
        // one query.
        $names = $this->resolveNames(
            $employees->getCollection()->flatMap(
                fn (Employee $e) => $this->effectiveLayerIds($e, $overrides->get($e->employee_id)),
            ),
        );

        $employees->through(function (Employee $e) use ($overrides, $names) {
            $ids = $this->effectiveLayerIds($e, $overrides->get($e->employee_id));

            return [
                'employee_id' => $e->employee_id,
                'name' => $e->fullname,
                'pt' => $e->company_name,
                'area' => $e->office_area,
                'bu' => $e->group_company,
                // Ordered effective chain (variable length) for the edit modal.
                'layers' => collect($ids)->map(fn (string $id) => [
                    'id' => $id,
                    'name' => $names[$id] ?? null,
                ])->all(),
                'has_override' => $overrides->has($e->employee_id),
            ];
        });

        return Inertia::render('Admin/ApprovalSetting', [
            'employees' => $employees,
            'filters' => $filters,
            'sort' => $sort,
            'filterOptions' => $this->filterOptions($user),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function readFilters(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'bu' => $request->string('bu')->value(),
            'area' => $request->string('area')->value(),
            'pt' => $request->string('pt')->value(),
        ];
    }

    /**
     * Scoped employee query with the list's search + BU/Area/PT filters applied.
     *
     * @param  array<string, string>  $filters
     */
    private function filteredQuery($user, array $filters)
    {
        return $this->scope->query($user)
            ->when($filters['search'], fn ($q, $term) => $q->where(function ($sub) use ($term) {
                $sub->where('fullname', 'like', "%{$term}%")
                    ->orWhere('employee_id', 'like', "%{$term}%");
            }))
            ->when($filters['bu'], fn ($q, $v) => $q->where('group_company', $v))
            ->when($filters['area'], fn ($q, $v) => $q->where('office_area', $v))
            ->when($filters['pt'], fn ($q, $v) => $q->where('company_name', $v));
    }

    /**
     * Distinct BU / Area / PT values within the user's visible employee set.
     *
     * @return array<string, Collection<int, string>>
     */
    private function filterOptions($user): array
    {
        $pluckDistinct = fn (string $column) => $this->scope->query($user)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->values();

        return [
            'businessUnits' => $pluckDistinct('group_company'),
            'areas' => $pluckDistinct('office_area'),
            'pts' => $pluckDistinct('company_name'),
        ];
    }

    public function update(UpdateApprovalSuperiorRequest $request, string $employeeId): RedirectResponse
    {
        abort_unless($this->scope->canView($request->user(), $employeeId), 403);

        // Ordered, de-duplicated, non-empty approver ids.
        $layers = collect($request->validated()['layers'] ?? [])
            ->map(fn ($v) => is_string($v) ? trim($v) : $v)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->unique()
            ->values()
            ->all();

        ApprovalSuperior::updateOrCreate(
            ['employee_id' => $employeeId],
            ['layers' => $layers, 'updated_by' => $request->user()->id],
        );

        ApprovalSuperiorHistory::create([
            'employee_id' => $employeeId,
            'layers' => $layers,
            'changed_by' => $request->user()->id,
            'changed_by_name' => $request->user()->name,
            'created_at' => now(),
        ]);

        return back()->with('success', 'Approval layers updated successfully.');
    }

    /**
     * Change log for one employee, newest first, with approver names resolved.
     */
    public function history(Request $request, string $employeeId): JsonResponse
    {
        abort_unless($this->scope->canView($request->user(), $employeeId), 403);

        $entries = ApprovalSuperiorHistory::where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $names = $this->resolveNames(
            $entries->flatMap(fn ($e) => $e->layers ?? [])->filter(),
        );

        return response()->json(
            $entries->map(fn ($e) => [
                'id' => $e->id,
                'changed_by_name' => $e->changed_by_name,
                'created_at' => $e->created_at?->toDateTimeString(),
                'layers' => collect($e->layers ?? [])
                    ->filter(fn ($id) => $id !== null && $id !== '')
                    ->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? null])
                    ->values()
                    ->all(),
            ]),
        );
    }

    /**
     * Live employee search for the layer pickers.
     */
    public function searchEmployees(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q', ''));

        $employees = Employee::query()
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($sub) use ($term) {
                    $sub->where('employee_id', 'like', "%{$term}%")
                        ->orWhere('fullname', 'like', "%{$term}%")
                        ->orWhere('designation_name', 'like', "%{$term}%");
                });
            })
            ->orderBy('fullname')
            ->limit(30)
            ->get(['employee_id', 'fullname', 'designation_name', 'group_company']);

        return response()->json($employees);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $import = new ApprovalLayerImport($request->user()->id, $request->user()->name);
        Excel::import($import, $request->file('file'));

        $message = "Imported {$import->imported()} employee layer(s).";
        if ($import->errors()) {
            return back()->with('error', $message.' '.count($import->errors()).' row(s) skipped.');
        }

        return back()->with('success', $message);
    }

    /**
     * The ordered effective approver ids for an employee: a saved override wins,
     * otherwise the chain defaults to the corporate manager_l1 / manager_l2.
     *
     * @return list<string>
     */
    private function effectiveLayerIds(Employee $employee, ?ApprovalSuperior $override): array
    {
        if ($override && ! empty($override->layers)) {
            return collect($override->layers)
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->values()
                ->all();
        }

        return collect([$employee->manager_l1_id, $employee->manager_l2_id])
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->values()
            ->all();
    }

    /**
     * Map a set of employee_ids to full names in one guarded query.
     *
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
            return Employee::whereIn('employee_id', $ids)
                ->pluck('fullname', 'employee_id')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
