<?php

namespace App\Jobs;

use App\Jobs\Bulk\BulkPdfExport;
use App\Models\JobStatus;
use App\Services\FacecardVisibility;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Starts a bulk facecard PDF zip, with progress on the uuid JobStatus row the
 * frontend polls. The work runs as a chain of short jobs ({@see BulkPdfExport});
 * this entry job only lays it out.
 */
class GenerateFacecardZip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $employeeIds
     * @param  array<string, bool>  $visible  the requester's {@see FacecardVisibility}
     */
    public function __construct(
        public array $employeeIds,
        public string $jobStatusId,
        public array $visible = [],
    ) {}

    public function handle(): void
    {
        if (! JobStatus::whereKey($this->jobStatusId)->update(['status' => 'processing', 'progress' => 0])) {
            return;
        }

        // A job queued before this field existed carries none: show nothing
        // restricted rather than everything.
        $visible = array_merge(array_fill_keys(array_keys(FacecardVisibility::FIELDS), false), $this->visible);

        BulkPdfExport::start('facecard', $this->employeeIds, $this->jobStatusId, $visible);
    }

    public function failed(\Throwable $e): void
    {
        Bulk\ZipBulkPdfs::markFailed($this->jobStatusId, $e);
    }
}
