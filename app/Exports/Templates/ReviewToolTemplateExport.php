<?php

namespace App\Exports\Templates;

use App\Models\ReviewTool;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The starter workbook for a Review Tools import.
 *
 * The smallest of the master templates, because the master is: a bilingual name
 * and an active flag, pointing at nothing. So there is no scope to look up —
 * the only reference worth carrying is the catalogue as it stands, which is
 * what says whether a name would add a tool or edit one.
 */
class ReviewToolTemplateExport implements WithMultipleSheets
{
    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            GuideSheet::make('REVIEW TOOLS — IMPORT GUIDE', [
                [
                    'heading' => 'WHAT THIS FILE IS FOR',
                    'rows' => [
                        ['', 'Adding review tools, or editing the ones you already have.', ''],
                        ['', 'A review tool is what an IDP item is reviewed with — a name, and whether it is still in use.', ''],
                    ],
                ],
                [
                    'heading' => 'THE SHEETS IN THIS FILE',
                    'columns' => ['Sheet', 'Fill in or reference?', 'What it is for'],
                    'rows' => [
                        [
                            '1. Review Tools',
                            GuideSheet::IMPORTED,
                            'One row per review tool. This is the only sheet that is saved.',
                        ],
                        [
                            'Ref - Existing Review Tools',
                            GuideSheet::REFERENCE,
                            'The tools already in the system. Check here first so you can tell an addition from an edit.',
                        ],
                    ],
                ],
                [
                    'heading' => 'HOW TO FILL IT IN',
                    'columns' => ['Step', 'Do this', ''],
                    'rows' => [
                        ['Step 1', 'Open "Ref - Existing Review Tools" and see what is already there.', ''],
                        ['Step 2', 'On "1. Review Tools", replace the example rows with your own.', ''],
                        ['Step 3', 'Save the file and upload it in the Import Center under "Review Tools".', ''],
                    ],
                ],
                [
                    'heading' => 'THE COLUMNS',
                    'columns' => ['Column', 'Required?', 'What to put in it'],
                    'rows' => [
                        ['name_en', 'Required', 'The English name. Up to 255 characters. This is also how the row is matched.'],
                        ['name_id', 'Optional', 'The Indonesian name, shown when the app is set to Indonesian.'],
                        ['is_active', 'Optional', 'yes or no. Leave it blank and the tool is active. An inactive tool stays on the IDP items already using it, but is off the list for new ones.'],
                    ],
                ],
                GuideSheet::goodToKnow(
                    'A row with a name that already exists edits that tool. Any other name adds a new one.',
                    [[
                        'Renaming a tool',
                        'Not possible here — you get a second tool.',
                        'A review tool has no code, so its name is its identity. Rename it on the Review Tools screen instead.',
                    ]],
                ),
            ]),

            new ArraySheet(
                '1. Review Tools',
                ['name_en', 'name_id', 'is_active'],
                [
                    ['Behavioural Event Interview', 'Wawancara Peristiwa Perilaku', 'yes'],
                    ['Coaching Log Review', 'Tinjauan Catatan Coaching', 'yes'],
                    ['Portfolio Review', 'Tinjauan Portofolio', 'yes'],
                ],
            ),

            new ArraySheet(
                'Ref - Existing Review Tools',
                ['Review tool', 'Indonesian name', 'Active'],
                $this->existing(),
                ['A' => 34, 'B' => 34],
            ),
        ];
    }

    /**
     * The catalogue as it stands — reference only, so that a filler can tell an
     * addition from an edit before uploading rather than after.
     *
     * @return list<list<string>>
     */
    private function existing(): array
    {
        try {
            $tools = ReviewTool::orderBy('name_en')->get();
        } catch (\Throwable) {
            return [['(the review tools could not be read)', '', '']];
        }

        if ($tools->isEmpty()) {
            return [['(no review tool has been created yet)', '', '']];
        }

        return $tools->map(fn (ReviewTool $tool) => [
            (string) $tool->name_en,
            (string) ($tool->name_id ?? ''),
            $tool->is_active ? 'yes' : 'no',
        ])->all();
    }
}
