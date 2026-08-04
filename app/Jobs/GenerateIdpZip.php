<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\JobStatus;
use App\Services\IdpService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Renders each requested employee's IDP to a PDF and bundles them into one zip,
 * reporting progress through a JobStatus row that the frontend polls.
 */
class GenerateIdpZip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DIR = 'idp-zips';

    /**
     * @param  array<int, string>  $employeeIds
     */
    public function __construct(
        public array $employeeIds,
        public string $jobStatusId,
    ) {
    }

    public function handle(IdpService $idp): void
    {
        $status = JobStatus::find($this->jobStatusId);
        if (! $status) {
            return;
        }

        $status->update(['status' => 'processing', 'progress' => 0]);

        Storage::disk('local')->makeDirectory(self::DIR);
        $fileName = 'idp_bulk_'.$this->jobStatusId.'.zip';
        $absolutePath = Storage::disk('local')->path(self::DIR.'/'.$fileName);

        $zip = new ZipArchive();
        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $status->update(['status' => 'failed', 'error_message' => 'Could not create zip archive.']);

            return;
        }

        $total = max(count($this->employeeIds), 1);
        $done = 0;

        foreach ($this->employeeIds as $employeeId) {
            $employee = Employee::where('employee_id', $employeeId)->first();

            if ($employee) {
                $data = $idp->manageData($employeeId);
                $pdf = Pdf::loadView('pdf.idp', [
                    'employee' => $employee,
                    'developmentModels' => $data['developmentModels'],
                ]);
                $zip->addFromString("idp_{$employeeId}.pdf", $pdf->output());
            }

            $done++;
            $status->update(['progress' => (int) round($done / $total * 100)]);
        }

        $zip->close();

        $status->update([
            'status' => 'completed',
            'progress' => 100,
            'file_name' => $fileName,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        JobStatus::where('id', $this->jobStatusId)->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
