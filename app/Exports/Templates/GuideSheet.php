<?php

namespace App\Exports\Templates;

/**
 * The "START HERE" sheet every import template opens on.
 *
 * It exists because a template is not self-explanatory: a workbook of six tabs
 * says nothing about which of them are written to the database and which are
 * only there to copy values from, nor how the tabs relate. Building all five
 * guides through one place keeps them saying the same thing the same way, so
 * learning one template teaches the rest.
 *
 * A section is a banded heading, an optional bold column row, and its rows.
 */
class GuideSheet
{
    /** What the "Fill in or reference?" column says, so the wording never drifts. */
    public const IMPORTED = 'FILL IN — imported';

    public const REFERENCE = 'Reference only — never imported';

    /**
     * @param  list<array{heading: string, columns?: list<string>, rows: list<list<string>>}>  $sections
     */
    public static function make(string $bannerTitle, array $sections): ArraySheet
    {
        $rows = [];
        $banded = [];

        foreach ($sections as $section) {
            if ($rows !== []) {
                $rows[] = ['', '', ''];
            }

            $rows[] = [$section['heading'], '', ''];
            $banded[] = count($rows);

            if (isset($section['columns'])) {
                $rows[] = array_pad($section['columns'], 3, '');
                $banded[] = count($rows);
            }

            foreach ($section['rows'] as $row) {
                $rows[] = array_pad($row, 3, '');
            }
        }

        return new ArraySheet(
            'START HERE',
            [$bannerTitle, '', ''],
            $rows,
            ['A' => 30, 'B' => 34, 'C' => 62],
            $banded,
        );
    }

    /**
     * The standard closing section: what the import does to data that is
     * already there. Identical across every template, so it is written once.
     *
     * @param  list<list<string>>  $extra  template-specific rules, appended
     * @return array{heading: string, columns: list<string>, rows: list<list<string>>}
     */
    public static function goodToKnow(string $matching, array $extra = []): array
    {
        return [
            'heading' => 'GOOD TO KNOW',
            'columns' => ['Topic', 'What happens', 'Why'],
            'rows' => array_merge([
                ['New or existing?', $matching, 'Nothing is ever duplicated by re-uploading the same file.'],
                [
                    'A row with a problem',
                    'Only that row is skipped.',
                    'Every other row still imports. The Import Center log lists what went wrong, row by row.',
                ],
                [
                    'Re-uploading',
                    'Safe. The same file twice leaves the same result.',
                    'Useful for correcting a few rows: fix them and upload the whole file again.',
                ],
            ], $extra),
        ];
    }
}
