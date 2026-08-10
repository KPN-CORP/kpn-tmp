<?php

namespace App\Jobs;

use App\Models\CompetencyAssessment;
use App\Models\Employee;
use App\Models\JobStatus;
use App\Models\PerformanceAppraisal;
use App\Models\ResultSummary;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Renders each requested employee's facecard to a PDF and bundles them into one
 * zip, reporting progress through a JobStatus row that the frontend polls.
 */
class GenerateFacecardZip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DIR = 'facecard-zips';

    /**
     * @param  array<int, string>  $employeeIds
     */
    public function __construct(
        public array $employeeIds,
        public string $jobStatusId,
    ) {}

    public function handle(): void
    {
        $status = JobStatus::find($this->jobStatusId);
        if (! $status) {
            return;
        }

        $status->update(['status' => 'processing', 'progress' => 0]);

        Storage::disk('local')->makeDirectory(self::DIR);
        $fileName = 'facecard_bulk_'.$this->jobStatusId.'.zip';
        $absolutePath = Storage::disk('local')->path(self::DIR.'/'.$fileName);

        $zip = new ZipArchive;
        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $status->update(['status' => 'failed', 'error_message' => 'Could not create zip archive.']);

            return;
        }

        $total = max(count($this->employeeIds), 1);
        $done = 0;

        foreach ($this->employeeIds as $employeeId) {
            $employee = Employee::where('employee_id', $employeeId)->first();

            if ($employee) {
                $pdf = Pdf::loadView('pdf.facecard', [
                    'employee' => $employee,
                    'competencyAssessments' => CompetencyAssessment::where('employee_id', $employeeId)
                        ->orderByDesc('period')->get(),
                    'appraisals' => $this->safeGet(fn () => PerformanceAppraisal::where('employee_id', $employeeId)
                        ->orderByDesc('appraisal_year')->get()),
                    'resultSummary' => ResultSummary::where('employee_id', $employeeId)->first(),
                ]);
                $zip->addFromString("facecard_{$employeeId}.pdf", $pdf->output());
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

    /**
     * Read kpncorp sub-data defensively — a missing table/column or an
     * unavailable connection yields an empty collection rather than a failure.
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
