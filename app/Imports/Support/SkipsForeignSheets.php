<?php

namespace App\Imports\Support;

use Illuminate\Support\Collection;

/**
 * Telling a workbook's own sheet from the ones it merely carries.
 *
 * An importer that is not `WithMultipleSheets` is handed **every** sheet in the
 * file — maatwebsite fills its sheet map with the same object once per sheet —
 * and the templates all carry a "How to use" tab plus reference tabs. Left
 * alone, a single-sheet importer reads those as data.
 *
 * The test is the heading row: a sheet is ours only when it carries **all** of
 * the columns we key on. One is not enough — a reference sheet headed
 * "Development model" slugs to the same key as the real one — so each importer
 * names the smallest set no other tab can satisfy.
 *
 * Matching on headings rather than on the sheet's name is deliberate: it keeps
 * a plain CSV, or a sheet somebody renamed, importable.
 */
trait SkipsForeignSheets
{
    /** @var list<string> */
    private array $expectedHeadings = [];

    private bool $sawOurSheet = false;

    /**
     * @param  Collection<int, mixed>  $rows
     * @param  list<string>  $required  heading keys that together identify our sheet
     * @param  list<string>  $forbidden  heading keys that rule a sheet out, for an
     *                                   importer whose own columns are a subset of
     *                                   another template's
     */
    protected function isOurSheet(Collection $rows, array $required, array $forbidden = []): bool
    {
        $this->expectedHeadings = $required;

        if ($rows->isEmpty()) {
            return false;
        }

        $headings = collect($rows->first())->keys();

        foreach ($required as $key) {
            if (! $headings->contains($key)) {
                return false;
            }
        }

        foreach ($forbidden as $key) {
            if ($headings->contains($key)) {
                return false;
            }
        }

        $this->sawOurSheet = true;

        return true;
    }

    /**
     * Why nothing was read, when no sheet in the file turned out to be ours.
     *
     * Without this the import is a silent no-op — "no X was imported", no
     * errors — which reads as though the file were empty rather than as though
     * its heading row were wrong.
     */
    protected function missingSheetError(): ?string
    {
        if ($this->sawOurSheet || $this->expectedHeadings === []) {
            return null;
        }

        return 'No sheet in this file has the expected heading row (needs at least: '
            .implode(', ', $this->expectedHeadings).'). Start from the template.';
    }

    /**
     * The collected errors, with the "wrong file" one appended when it applies.
     *
     * @param  array<int, string>  $errors
     * @return array<int, string>
     */
    protected function withSheetError(array $errors): array
    {
        return array_values(array_filter([...$errors, $this->missingSheetError()]));
    }
}
