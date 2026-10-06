<?php

namespace Tests\Unit;

use App\Jobs\Bulk\BulkPdfExport;
use App\Jobs\Bulk\RenderPdfBatch;
use App\Jobs\Bulk\ZipBulkPdfs;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * A bulk export is a chain of short jobs, so no single job runs into the
 * worker timeout or the queue's retry_after however many employees it covers.
 */
class BulkPdfExportTest extends TestCase
{
    public function test_the_export_is_split_into_batches_then_zipped(): void
    {
        Bus::fake();

        $ids = array_map(fn ($i) => sprintf('E%03d', $i), range(1, 40));
        BulkPdfExport::start('idp', $ids, 'status-1', ['nine_box' => true]);

        // 40 employees at 15 per batch: 15 + 15 + 10, then the zip.
        Bus::assertChained([RenderPdfBatch::class, RenderPdfBatch::class, RenderPdfBatch::class, ZipBulkPdfs::class]);

        Bus::assertDispatched(RenderPdfBatch::class, function (RenderPdfBatch $first) use ($ids) {
            $rest = array_map('unserialize', $first->chained);

            $batches = [$first, $rest[0], $rest[1]];

            return array_merge(...array_map(fn ($b) => $b->employeeIds, $batches)) === $ids
                && array_map(fn ($b) => $b->doneBefore, $batches) === [0, 15, 30]
                && array_map(fn ($b) => $b->total, $batches) === [40, 40, 40]
                && $first->visible === ['nine_box' => true]
                && $rest[2] instanceof ZipBulkPdfs
                && $rest[2]->jobStatusId === 'status-1';
        });
    }

    public function test_every_job_finishes_inside_the_queue_retry_window(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        foreach ([new RenderPdfBatch('idp', 's', [], 0, 0), new ZipBulkPdfs('idp', 's')] as $job) {
            $this->assertLessThan($retryAfter, $job->timeout, $job::class.' could outlive retry_after and run twice');
            $this->assertSame(1, $job->tries);
        }
    }

    public function test_an_empty_export_still_produces_its_zip_step(): void
    {
        Bus::fake();

        BulkPdfExport::start('facecard', [], 'status-2');

        Bus::assertDispatched(ZipBulkPdfs::class, fn (ZipBulkPdfs $job) => $job->kind === 'facecard');
    }
}
