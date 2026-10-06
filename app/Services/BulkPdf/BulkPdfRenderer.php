<?php

namespace App\Services\BulkPdf;

/**
 * Renders one kind of per-employee PDF for the bulk zip exports, a batch at a
 * time, so each batch can read what it needs in a handful of `whereIn` queries
 * instead of several queries per employee.
 */
interface BulkPdfRenderer
{
    /**
     * @param  list<string>  $employeeIds
     * @param  array<string, bool>  $visible  the requester's facecard field visibility
     * @return iterable<string, string> file name inside the zip => PDF bytes
     */
    public function render(array $employeeIds, array $visible): iterable;
}
