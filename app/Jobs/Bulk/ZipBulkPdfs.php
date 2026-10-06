<?php

namespace App\Jobs\Bulk;

use App\Models\JobStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/** The last link of a {@see BulkPdfExport}: zips the rendered PDFs and marks the export complete. */
class ZipBulkPdfs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 80;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $kind,
        public string $jobStatusId,
    ) {}

    public function handle(): void
    {
        $status = JobStatus::find($this->jobStatusId);
        if (! $status) {
            Storage::disk('local')->deleteDirectory(BulkPdfExport::workDir($this->jobStatusId));

            return;
        }

        $kind = BulkPdfExport::KINDS[$this->kind];
        $disk = Storage::disk('local');
        $workDir = BulkPdfExport::workDir($this->jobStatusId);
        $fileName = $kind['prefix'].$this->jobStatusId.'.zip';

        $disk->makeDirectory($kind['dir']);

        $zip = new ZipArchive;
        if ($zip->open($disk->path($kind['dir'].'/'.$fileName), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create zip archive.');
        }

        foreach ($disk->files($workDir) as $path) {
            $zip->addFile($disk->path($path), basename($path));
        }

        // An export where no employee resolved still yields a valid (empty) zip.
        $zip->close();
        $disk->deleteDirectory($workDir);

        $status->update([
            'status' => 'completed',
            'progress' => 100,
            'file_name' => $fileName,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        self::markFailed($this->jobStatusId, $e);
    }

    /** Fail the export the poller is watching, and drop its half-built working folder. */
    public static function markFailed(string $jobStatusId, \Throwable $e): void
    {
        report($e);

        JobStatus::where('id', $jobStatusId)->update([
            'status' => 'failed',
            // Shown to the user by the poller; the detail is in the log.
            'error_message' => 'The export could not be completed. Please try again.',
        ]);

        Storage::disk('local')->deleteDirectory(BulkPdfExport::workDir($jobStatusId));
    }
}
