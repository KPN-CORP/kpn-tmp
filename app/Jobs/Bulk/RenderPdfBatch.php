<?php

namespace App\Jobs\Bulk;

use App\Models\JobStatus;
use App\Services\BulkPdf\BulkPdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** One batch of a {@see BulkPdfExport}: renders a few employees' PDFs into the export's working folder. */
class RenderPdfBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Under the database queue's 90 s `retry_after`, so no second worker ever picks it up while it runs. */
    public int $timeout = 80;

    /** A retry would re-render into a folder a failed attempt half-filled; fail the export instead. */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    /**
     * @param  list<string>  $employeeIds
     * @param  array<string, bool>  $visible
     */
    public function __construct(
        public string $kind,
        public string $jobStatusId,
        public array $employeeIds,
        public int $doneBefore,
        public int $total,
        public array $visible = [],
    ) {}

    public function handle(): void
    {
        $status = JobStatus::find($this->jobStatusId);
        if (! $status) {
            // The export was cleared away; stop the rest of the chain too.
            $this->delete();

            return;
        }

        /** @var BulkPdfRenderer $renderer */
        $renderer = app(BulkPdfExport::KINDS[$this->kind]['renderer']);
        $dir = BulkPdfExport::workDir($this->jobStatusId);
        $disk = Storage::disk('local');

        foreach ($renderer->render($this->employeeIds, $this->visible) as $fileName => $bytes) {
            $disk->put($dir.'/'.$fileName, $bytes);
        }

        // Rendering is the bulk of the work; the last few percent are the zip.
        $done = $this->doneBefore + count($this->employeeIds);
        $status->update([
            'status' => 'processing',
            'progress' => (int) floor($done / max($this->total, 1) * 95),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        ZipBulkPdfs::markFailed($this->jobStatusId, $e);
    }
}
