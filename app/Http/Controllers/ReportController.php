<?php

namespace App\Http\Controllers;

use App\Exports\ReportExport;
use App\Http\Controllers\Concerns\ReadsSort;
use App\Models\Employee;
use App\Models\IndividualDevelopmentPlan;
use App\Models\PerformanceAppraisal;
use App\Services\EmployeeScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * HC Report — a scoped, filterable roster of Potential / Talent Box (from the
 * corporate performance appraisals) and IDP Progress, mirroring facecard's
 * report screen. Columns are gated by the download_talent / download_idp_progress
 * permissions; the whole screen is gated by view_report_menu in routes/web.php.
 */
class ReportController extends Controller
{
    use ReadsSort;

    /**
     * Sortable columns (row keys map 1:1 to kpncorp employee columns).
     *
     * @var list<string>
     */
    private const SORTABLE = [
        'employee_id', 'fullname', 'group_company', 'job_level', 'designation_name', 'unit',
    ];

    public function __construct(private readonly EmployeeScopeService $scope) {}

    /**
     * Paginated, filtered report — scoped to what the user may see.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = $this->readFilters($request);
        $sort = $this->readSort($request, self::SORTABLE, 'fullname');

        $canTalent = $user->can('download_talent');
        $canIdp = $user->can('download_idp_progress');

        $rows = $this->filteredQuery($user, $filters)
            ->with($this->eagerLoads($filters['year'], $canTalent, $canIdp))
            ->orderBy($sort['key'], $sort['dir'])
            ->paginate((int) $request->integer('per_page', 10))
            ->withQueryString()
            ->through(fn (Employee $employee) => $this->reportRow($employee, $canTalent, $canIdp));

        return Inertia::render('Report/Index', [
            'rows' => $rows,
            'filters' => $filters,
            'sort' => $sort,
            'filterOptions' => $this->filterOptions($user),
            'availableYears' => $this->availableYears(),
            'can' => ['talent' => $canTalent, 'idp' => $canIdp],
        ]);
    }

    /**
     * Export the current scoped + filtered report to Excel.
     */
    public function exportExcel(Request $request): BinaryFileResponse
    {
        $request->validate([
            'report_name' => 'required|string|in:talent_report,idp_progress',
        ]);

        $user = $request->user();
        $type = $request->string('report_name')->value();

        abort_unless(
            $type === 'talent_report' ? $user->can('download_talent') : $user->can('download_idp_progress'),
            403,
        );

        $filters = $this->readFilters($request);
        $canTalent = $type === 'talent_report';
        $canIdp = $type === 'idp_progress';

        $rows = $this->filteredQuery($user, $filters)
            ->with($this->eagerLoads($filters['year'], $canTalent, $canIdp))
            ->orderBy('fullname')
            ->get()
            ->map(fn (Employee $employee) => $this->reportRow($employee, $canTalent, $canIdp));

        $yearString = $filters['year'] ?: 'All-Years';
        $fileName = 'HC_Report_'.$type.'_'.$yearString.'_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(new ReportExport($rows, $type, $filters['year'] ?: null), $fileName);
    }

    /**
     * @return array<string, string>
     */
    private function readFilters(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'year' => $request->string('year')->value(),
            'business_unit' => $request->string('business_unit')->value(),
            'job_level' => $request->string('job_level')->value(),
            'designation' => $request->string('designation')->value(),
            'unit' => $request->string('unit')->value(),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function filteredQuery($user, array $filters): Builder
    {
        return $this->scope->query($user)
            ->when($filters['search'], fn ($q, $term) => $q->where(function ($sub) use ($term) {
                $sub->where('fullname', 'like', "%{$term}%")
                    ->orWhere('employee_id', 'like', "%{$term}%");
            }))
            ->when($filters['business_unit'], fn ($q, $v) => $q->where('group_company', $v))
            ->when($filters['job_level'], fn ($q, $v) => $q->where('job_level', $v))
            ->when($filters['designation'], fn ($q, $v) => $q->where('designation_name', $v))
            ->when($filters['unit'], fn ($q, $v) => $q->where('unit', $v));
    }

    /**
     * Eager-load constraints for the year in view. Both relations may hop
     * connections; eager loading across connections is supported (whereHas is
     * not, which is why the year is applied here rather than as a query filter).
     *
     * @return array<string, \Closure>
     */
    private function eagerLoads(string $year, bool $canTalent, bool $canIdp): array
    {
        $loads = [];

        if ($canTalent) {
            $loads['performanceAppraisals'] = function ($q) use ($year) {
                $year !== ''
                    ? $q->where('appraisal_year', $year)
                    : $q->orderByDesc('appraisal_year');
            };
        }

        if ($canIdp) {
            $loads['developmentPlans'] = function ($q) use ($year) {
                if ($year !== '') {
                    $q->whereYear('time_frame_end', $year);
                }
            };
        }

        return $loads;
    }

    /**
     * Shape one employee into a report row.
     *
     * @return array<string, mixed>
     */
    private function reportRow(Employee $employee, bool $canTalent, bool $canIdp): array
    {
        $row = [
            'employee_id' => $employee->employee_id,
            'fullname' => $employee->fullname,
            'group_company' => $employee->group_company ?: 'N.A.',
            'job_level' => $employee->job_level ?: 'N.A.',
            'designation_name' => $employee->designation_name ?: 'N.A.',
            'unit' => $employee->unit ?: 'N.A.',
            'potential' => 'N.A.',
            'talent_box' => 'N.A.',
            'idp_progress' => 'N.A.',
        ];

        if ($canTalent) {
            $appraisal = $employee->performanceAppraisals->first();
            $row['potential'] = $appraisal->potential ?? 'N.A.';
            $row['talent_box'] = $appraisal->talent_box ?? 'N.A.';
        }

        if ($canIdp) {
            $plans = $employee->developmentPlans;
            $total = $plans->count();
            $completed = $plans->filter(
                fn ($plan) => ! empty($plan->result_evidence) && $plan->result_evidence !== '-',
            )->count();

            $row['idp_progress'] = $total > 0 ? "{$completed}/{$total}" : '0/0';
        }

        return $row;
    }

    /**
     * Distinct filter values within the user's visible employee set.
     *
     * @return array<string, Collection>
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
            'units' => $pluckDistinct('unit'),
        ];
    }

    /**
     * Years that have either a performance appraisal or an IDP end date,
     * newest first. Read defensively so a missing connection never 500s.
     *
     * @return Collection<int, int>
     */
    private function availableYears(): Collection
    {
        $paYears = $this->safeGet(fn () => PerformanceAppraisal::query()
            ->select('appraisal_year')->distinct()->pluck('appraisal_year'));

        $idpYears = $this->safeGet(fn () => IndividualDevelopmentPlan::query()
            ->whereNotNull('time_frame_end')
            ->selectRaw('YEAR(time_frame_end) as year')->distinct()->pluck('year'));

        return $paYears->merge($idpYears)
            ->filter()
            ->map(fn ($year) => (int) $year)
            ->unique()
            ->sortDesc()
            ->values();
    }

    /**
     * Run a query defensively — a missing table / unreachable connection yields
     * an empty collection rather than a 500.
     */
    private function safeGet(callable $callback): Collection
    {
        try {
            return collect($callback());
        } catch (\Throwable) {
            return collect();
        }
    }
}
