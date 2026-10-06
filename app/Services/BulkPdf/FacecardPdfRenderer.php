<?php

namespace App\Services\BulkPdf;

use App\Models\CompetencyAssessment;
use App\Models\Employee;
use App\Models\PerformanceAppraisal;
use App\Models\ResultSummary;
use App\Services\FacecardVisibility;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * One facecard PDF per employee. The restricted fields are stripped with the
 * requester's visibility, exactly as the single-employee PDF does.
 */
class FacecardPdfRenderer implements BulkPdfRenderer
{
    public function render(array $employeeIds, array $visible): iterable
    {
        $employees = Employee::whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id');

        $assessments = CompetencyAssessment::whereIn('employee_id', $employeeIds)
            ->orderByDesc('period')
            ->get()
            ->groupBy('employee_id');

        $summaries = ResultSummary::whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id');

        $appraisals = $this->appraisals($employeeIds);

        foreach ($employeeIds as $employeeId) {
            $employee = $employees->get($employeeId);

            if (! $employee) {
                continue;
            }

            yield "facecard_{$employeeId}.pdf" => Pdf::loadView('pdf.facecard', [
                'employee' => $employee,
                'competencyAssessments' => FacecardVisibility::assessments(
                    $assessments->get($employeeId) ?? collect(),
                    $visible,
                ),
                'appraisals' => FacecardVisibility::appraisals($appraisals->get($employeeId) ?? collect(), $visible),
                'resultSummary' => FacecardVisibility::resultSummary($summaries->get($employeeId), $visible),
                'visible' => $visible,
            ])->output();
        }
    }

    /**
     * Corporate 9-box rows, read defensively: the table lives on kpncorp, and a
     * missing table or an unreachable connection leaves the section empty
     * rather than failing the whole export.
     *
     * @param  list<string>  $employeeIds
     * @return Collection<string, Collection<int, PerformanceAppraisal>>
     */
    private function appraisals(array $employeeIds): Collection
    {
        try {
            return PerformanceAppraisal::whereIn('employee_id', $employeeIds)
                ->orderByDesc('appraisal_year')
                ->get()
                ->groupBy('employee_id');
        } catch (QueryException $e) {
            report($e);

            return collect();
        }
    }
}
