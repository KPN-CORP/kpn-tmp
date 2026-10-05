<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ReadsPerPage
{
    /**
     * Read the page size from the request, clamped to what the pager offers.
     * Unclamped, `?per_page=100000` would load a whole table in one request.
     */
    protected function perPage(Request $request, int $default = 10, int $max = 100): int
    {
        return max(1, min($max, $request->integer('per_page', $default)));
    }
}
