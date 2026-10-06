<?php

namespace App\Jobs\Bulk;

use App\Services\BulkPdf\FacecardPdfRenderer;
use App\Services\BulkPdf\IdpPdfRenderer;
use Illuminate\Support\Facades\Bus;

/**
 * A bulk PDF export, run as a CHAIN of short jobs rather than one long one:
 * each {@see RenderPdfBatch} renders a few employees' PDFs into a working
 * folder, then {@see ZipBulkPdfs} bundles them and marks the export done.
 *
 * Why a chain: the queue hands a job to another worker once `retry_after`
 * (90 s on the database connection) passes, and a worker kills a job after its
 * timeout. A single job rendering a few hundred PDFs runs past both, so it was
 * either killed mid-way or run twice. Each batch here stays well under both,
 * and the export as a whole can take as long as it needs — without a dedicated
 * queue or worker, and without raising `retry_after` for every other job.
 */
final class BulkPdfExport
{
    /** Employees rendered per job. Small enough to finish far inside the timeout. */
    public const BATCH = 15;

    /**
     * Per kind: the renderer, where the finished zip is written (the bulk-file
     * endpoints read it from there), and its file-name prefix.
     *
     * @var array<string, array{renderer: class-string, dir: string, prefix: string}>
     */
    public const KINDS = [
        'idp' => ['renderer' => IdpPdfRenderer::class, 'dir' => 'idp-zips', 'prefix' => 'idp_bulk_'],
        'facecard' => ['renderer' => FacecardPdfRenderer::class, 'dir' => 'facecard-zips', 'prefix' => 'facecard_bulk_'],
    ];

    /** Working folder for one export's PDFs, removed once they are zipped. */
    public static function workDir(string $jobStatusId): string
    {
        return 'bulk-pdfs/'.$jobStatusId;
    }

    /**
     * @param  list<string>  $employeeIds
     * @param  array<string, bool>  $visible  the requester's facecard field visibility
     */
    public static function start(string $kind, array $employeeIds, string $jobStatusId, array $visible = []): void
    {
        $employeeIds = array_values($employeeIds);
        $total = count($employeeIds);
        $jobs = [];
        $done = 0;

        foreach (array_chunk($employeeIds, self::BATCH) as $batch) {
            $jobs[] = new RenderPdfBatch($kind, $jobStatusId, $batch, $done, $total, $visible);
            $done += count($batch);
        }

        $jobs[] = new ZipBulkPdfs($kind, $jobStatusId);

        Bus::chain($jobs)->dispatch();
    }
}
