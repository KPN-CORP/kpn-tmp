<?php

namespace App\Jobs;

use App\Jobs\Bulk\BulkPdfExport;
use App\Models\JobStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Starts a bulk IDP PDF zip: each requested employee's IDP for the active
 * cycle, with progress on the uuid JobStatus row the frontend polls.
 *
 * The work itself runs as a chain of short jobs ({@see BulkPdfExport}); this
 * entry job only lays it out, so it stays the controller's single dispatch
 * point and a job queued before the chain existed still runs.
 */
class GenerateIdpZip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $employeeIds
     */
    public function __construct(
        public array $employeeIds,
        public string $jobStatusId,
    ) {}

    public function handle(): void
    {
        if (! JobStatus::whereKey($this->jobStatusId)->update(['status' => 'processing', 'progress' => 0])) {
            return;
        }

        BulkPdfExport::start('idp', $this->employeeIds, $this->jobStatusId);
    }

    public function failed(\Throwable $e): void
    {
        Bulk\ZipBulkPdfs::markFailed($this->jobStatusId, $e);
    }
}
