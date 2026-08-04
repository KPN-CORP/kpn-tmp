<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ReadsSort
{
    /**
     * Read a whitelisted sort column + direction from the request.
     *
     * @param  array<int, string>  $allowed
     * @return array{key: string, dir: string}
     */
    protected function readSort(Request $request, array $allowed, string $default): array
    {
        $key = $request->string('sort')->value();
        $dir = strtolower($request->string('direction')->value()) === 'desc' ? 'desc' : 'asc';

        return [
            'key' => in_array($key, $allowed, true) ? $key : $default,
            'dir' => $dir,
        ];
    }
}
