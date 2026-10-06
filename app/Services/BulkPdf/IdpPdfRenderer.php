<?php

namespace App\Services\BulkPdf;

use App\Models\Employee;
use App\Services\IdpService;
use Barryvdh\DomPDF\Facade\Pdf;

/** One IDP PDF per employee, for the active cycle (the only one the screens show). */
class IdpPdfRenderer implements BulkPdfRenderer
{
    public function __construct(private readonly IdpService $idp) {}

    public function render(array $employeeIds, array $visible): iterable
    {
        $employees = Employee::whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id');

        foreach ($employeeIds as $employeeId) {
            $employee = $employees->get($employeeId);

            if (! $employee) {
                continue;
            }

            // No master option lists: the PDF never renders the plan form.
            $data = $this->idp->manageData($employeeId, withOptions: false);

            yield "idp_{$employeeId}.pdf" => Pdf::loadView('pdf.idp', [
                'employee' => $employee,
                'developmentModels' => $data['developmentModels'],
                'planning' => $data['planning'],
            ])->output();
        }
    }
}
