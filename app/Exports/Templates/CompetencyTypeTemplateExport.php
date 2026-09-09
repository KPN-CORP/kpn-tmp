<?php

namespace App\Exports\Templates;

use App\Models\BusinessUnit;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The starter workbook for a Master Competency Type import: the sheet to fill
 * in, plus a reference sheet listing the business units a row may name.
 *
 * The reference sheet is not decoration — `business_units` is checked against
 * the corporate master ({@see BusinessUnit}), so a unit spelled from memory is
 * the likeliest reason a row is rejected.
 *
 * Both sheets are built from the live master, so the workbook is uploadable
 * as-is in whatever environment it was downloaded from. A hard-coded fallback
 * covers kpncorp being unreachable.
 */
class CompetencyTypeTemplateExport implements WithMultipleSheets
{
    /**
     * Stand-in units for when kpncorp is unreachable and the live list comes
     * back empty. The sample then still shows the shape, even if the values
     * have to be corrected before upload.
     *
     * @var list<string>
     */
    private const FALLBACK_UNITS = ['Plantations', 'Property', 'Cement'];

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        $units = BusinessUnit::names() ?: self::FALLBACK_UNITS;

        return [
            GuideSheet::make('MASTER COMPETENCY TYPE — IMPORT GUIDE', [
                [
                    'heading' => 'WHAT THIS FILE IS FOR',
                    'rows' => [
                        ['', 'Adding competency types, or editing the ones you already have.', ''],
                        ['', 'A competency type is the heading competencies are filed under — Soft Competency, Technical Competency, and so on.', ''],
                    ],
                ],
                [
                    'heading' => 'THE SHEETS IN THIS FILE',
                    'columns' => ['Sheet', 'Fill in or reference?', 'What it is for'],
                    'rows' => [
                        [
                            '1. Master Competency Type',
                            GuideSheet::IMPORTED,
                            'One row per competency type. This is the only sheet that is saved.',
                        ],
                        [
                            'Ref - Business Units',
                            GuideSheet::REFERENCE,
                            'The company business units. Copy the names from here — a unit spelled any other way is rejected.',
                        ],
                    ],
                ],
                [
                    'heading' => 'HOW TO FILL IT IN',
                    'columns' => ['Step', 'Do this', ''],
                    'rows' => [
                        ['Step 1', 'On "1. Master Competency Type", replace the example rows with your own.', ''],
                        ['Step 2', 'For business_units, copy the names from the "Ref - Business Units" sheet.', ''],
                        ['Step 3', 'Save the file and upload it in the Import Center under "Master Competency Type".', ''],
                    ],
                ],
                [
                    'heading' => 'THE COLUMNS',
                    'columns' => ['Column', 'Required?', 'What to put in it'],
                    'rows' => [
                        ['code', 'Required', 'A short identifier, up to 50 characters — for example SOFT. It must be unique, and it is how the row is matched.'],
                        ['name_en', 'Required', 'The English name. Also unique.'],
                        ['name_id', 'Optional', 'The Indonesian name, shown when the app is set to Indonesian.'],
                        ['description_en / description_id', 'Optional', 'A sentence explaining what this type covers.'],
                        [
                            'business_units',
                            'Required',
                            'Which business units this type applies to. Put several in the one cell and separate them with a semicolon — for example: Plantations; Property',
                        ],
                    ],
                ],
                GuideSheet::goodToKnow(
                    'A row is matched on its code first, then on name_en. Anything unmatched adds a new type.',
                    [[
                        'Types with no code yet',
                        'Importing gives them one.',
                        'A row whose name_en matches an existing type assigns it that code — which is how the types created before codes existed get theirs.',
                    ]],
                ),
            ]),

            new ArraySheet(
                '1. Master Competency Type',
                ['code', 'name_en', 'name_id', 'description_en', 'description_id', 'business_units'],
                $this->samples($units),
            ),

            new ArraySheet(
                'Ref - Business Units',
                ['Business unit', 'How to use it'],
                $this->reference($units),
                ['B' => 52],
            ),
        ];
    }

    /**
     * @param  list<string>  $units
     * @return list<list<string>>
     */
    private function samples(array $units): array
    {
        $one = $units[0];
        $two = $units[1] ?? $one;
        $few = implode('; ', array_slice($units, 0, 3));

        return [
            [
                'SOFT',
                'Soft Competency',
                'Kompetensi Perilaku',
                'Behavioural competencies shared across every role.',
                'Kompetensi perilaku yang berlaku untuk seluruh peran.',
                $one.'; '.$two,
            ],
            [
                'TECH',
                'Technical Competency',
                'Kompetensi Teknis',
                'Job-specific skills and technical know-how.',
                'Keahlian teknis yang spesifik untuk suatu jabatan.',
                $one,
            ],
            [
                'LEAD',
                'Leadership Competency',
                'Kompetensi Kepemimpinan',
                'Competencies expected of people who lead a team.',
                'Kompetensi yang diharapkan dari pemimpin tim.',
                $few,
            ],
        ];
    }

    /**
     * @param  list<string>  $units
     * @return list<list<string>>
     */
    private function reference(array $units): array
    {
        $hint = "Copy into the business_units column. Separate several with ';'.";

        return array_map(
            fn (string $name, int $index) => [$name, $index === 0 ? $hint : ''],
            $units,
            array_keys($units),
        );
    }
}
