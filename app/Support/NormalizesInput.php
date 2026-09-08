<?php

namespace App\Support;

/**
 * Reading submitted scalars and lists the way the master-data forms send them.
 *
 * Every screen here posts ids as strings, empty selects as `""`, and list
 * fields as arrays that may carry blanks and duplicates — so the same three
 * conversions were being repeated at each call site.
 */
trait NormalizesInput
{
    /**
     * A submitted id as an int, or null when the form sent it empty.
     */
    protected function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * A submitted list of ids as unique ints.
     *
     * @return array<int, int>
     */
    protected function intList(mixed $values): array
    {
        return array_values(array_unique(array_map('intval', (array) $values)));
    }

    /**
     * A submitted list of raw strings, trimmed, blanks dropped, de-duplicated.
     *
     * @return array<int, string>
     */
    protected function stringList(mixed $values): array
    {
        return collect((array) $values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
