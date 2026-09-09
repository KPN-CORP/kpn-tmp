<?php

namespace App\Exports\Templates;

use App\Models\CompetencyType;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The starter workbook for a Master Competency import.
 *
 * A competency is not one row: it carries three independent child lists — the
 * parts it breaks down into, the rungs of its own proficiency ladder, and the
 * key behaviors observed at each rung. Two independent lists cannot share one
 * grid without a cross-product, so each gets a sheet of its own, joined back to
 * the competency by its `code`. Key behaviors join one step further, naming
 * their rung.
 *
 * A fifth sheet lists the competency types on offer, since `competency_type` is
 * checked against that master.
 *
 * The type used in the samples is read live, so the workbook is uploadable
 * as-is in whatever environment it was downloaded from.
 */
class CompetencyTemplateExport implements WithMultipleSheets
{
    /**
     * Named so the sample reads sensibly when there is no competency type to
     * borrow from yet.
     */
    private const FALLBACK_TYPE = 'Soft Competency';

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        $types = $this->types();

        // The catch-all "Others" makes a poor example of filing a competency,
        // so the samples skip it — the reference sheet still lists it.
        $usable = array_values(array_filter($types, fn (array $type) => ! $type['others']));

        $first = $usable[0]['ref'] ?? self::FALLBACK_TYPE;
        $second = $usable[1]['ref'] ?? $first;

        return [
            $this->guide(),

            new ArraySheet(
                '1. Master Competency',
                ['code', 'competency_type', 'name_en', 'name_id', 'description_en', 'description_id', 'is_active'],
                [
                    [
                        'COMM', $first, 'Communication', 'Komunikasi',
                        'Getting a message across clearly and listening well.',
                        'Menyampaikan pesan dengan jelas dan mendengarkan dengan baik.',
                        'yes',
                    ],
                    [
                        'PROB', $second, 'Problem Solving', 'Pemecahan Masalah',
                        'Breaking a problem down and working out what is really causing it.',
                        'Menguraikan masalah dan menemukan penyebab sebenarnya.',
                        'yes',
                    ],
                ],
            ),

            new ArraySheet(
                '2. Sub Competency',
                ['competency_code', 'name_en', 'name_id', 'description_en', 'description_id'],
                [
                    ['COMM', 'Active Listening', 'Mendengarkan Aktif', 'Hearing out the whole point before answering.', 'Menyimak sampai selesai sebelum menjawab.'],
                    ['COMM', 'Written Communication', 'Komunikasi Tertulis', 'Writing that is understood on the first read.', 'Tulisan yang dipahami sekali baca.'],
                    ['PROB', 'Root Cause Analysis', 'Analisis Akar Masalah', 'Getting past the symptom to the cause.', 'Menelusuri gejala sampai ke penyebab.'],
                ],
            ),

            new ArraySheet(
                '3. Proficiency Level',
                ['competency_code', 'name_en', 'name_id', 'description_en', 'description_id', 'is_active'],
                [
                    ['COMM', 'Basic', 'Dasar', 'Communicates within the immediate team.', 'Berkomunikasi di dalam tim sendiri.', 'yes'],
                    ['COMM', 'Intermediate', 'Menengah', 'Communicates across teams.', 'Berkomunikasi lintas tim.', 'yes'],
                    ['COMM', 'Advanced', 'Mahir', 'Communicates with external parties on the company behalf.', 'Berkomunikasi dengan pihak luar atas nama perusahaan.', 'yes'],
                    ['PROB', 'Basic', 'Dasar', 'Solves familiar problems with guidance.', 'Menyelesaikan masalah yang sudah dikenal dengan arahan.', 'yes'],
                    ['PROB', 'Advanced', 'Mahir', 'Solves problems with no precedent.', 'Menyelesaikan masalah yang belum ada contohnya.', 'yes'],
                ],
            ),

            new ArraySheet(
                '4. Key Behavior',
                ['competency_code', 'proficiency_level', 'name_en', 'name_id'],
                [
                    ['COMM', 'Basic', 'Listens without interrupting', 'Mendengarkan tanpa memotong'],
                    ['COMM', 'Basic', 'Shares information the team needs', 'Membagikan informasi yang dibutuhkan tim'],
                    ['COMM', 'Intermediate', 'Adapts the message to the audience', 'Menyesuaikan pesan dengan lawan bicara'],
                    ['COMM', 'Advanced', 'Facilitates discussion between conflicting parties', 'Memfasilitasi diskusi antar pihak yang berselisih'],
                    ['PROB', 'Basic', 'Identifies the symptoms of a problem', 'Mengenali gejala suatu masalah'],
                    ['PROB', 'Advanced', 'Designs a fix that holds', 'Merancang solusi yang bertahan'],
                ],
            ),

            new ArraySheet(
                'Ref - Competency Types',
                ['Use this value', 'Code', 'Name', 'How to use it'],
                $this->reference($types),
            ),
        ];
    }

    /**
     * The opening sheet. A competency is spread over four sheets, and nothing
     * about the first one says so — this is what tells a reader which tabs are
     * saved, which are only there to copy from, and how they join up.
     */
    private function guide(): ArraySheet
    {
        return GuideSheet::make('MASTER COMPETENCY — IMPORT GUIDE', [
            [
                'heading' => 'WHAT THIS FILE IS FOR',
                'rows' => [
                    ['', 'Adding competencies, or editing the ones you already have.', ''],
                    ['', 'A competency is bigger than one row: it breaks down into sub competencies, it has a ladder of proficiency levels, and each level has its key behaviors. That is why there are four sheets to fill in, not one.', ''],
                ],
            ],
            [
                'heading' => 'THE SHEETS IN THIS FILE',
                'columns' => ['Sheet', 'Fill in or reference?', 'What it is for'],
                'rows' => [
                    [
                        '1. Master Competency',
                        GuideSheet::IMPORTED,
                        'One row per competency. Its "code" is the identifier the other sheets point back to.',
                    ],
                    [
                        '2. Sub Competency',
                        GuideSheet::IMPORTED,
                        'The parts a competency breaks down into. As many rows per competency as you need.',
                    ],
                    [
                        '3. Proficiency Level',
                        GuideSheet::IMPORTED,
                        'The rungs of a competency ladder. As many rows per competency as you need.',
                    ],
                    [
                        '4. Key Behavior',
                        GuideSheet::IMPORTED,
                        'What is observed at one rung. As many rows per rung as you need.',
                    ],
                    [
                        'Ref - Competency Types',
                        GuideSheet::REFERENCE,
                        'The competency types on offer. Copy a value from here into the competency_type column.',
                    ],
                ],
            ],
            [
                'heading' => 'HOW THE SHEETS JOIN UP',
                'columns' => ['On this sheet', 'This column', 'Must match'],
                'rows' => [
                    ['2. Sub Competency', 'competency_code', 'a "code" on sheet 1.'],
                    ['3. Proficiency Level', 'competency_code', 'a "code" on sheet 1.'],
                    ['4. Key Behavior', 'competency_code', 'a "code" on sheet 1.'],
                    ['4. Key Behavior', 'proficiency_level', 'a "name_en" on sheet 3, for that same competency.'],
                    [
                        '',
                        'Worked example',
                        'Sheet 1 has code COMM. Sheet 3 has COMM / Basic. Sheet 4 has COMM / Basic / "Listens without interrupting" — that behavior lands under the first rung of Communication.',
                    ],
                ],
            ],
            [
                'heading' => 'HOW TO FILL IT IN',
                'columns' => ['Step', 'Do this', ''],
                'rows' => [
                    ['Step 1', 'On sheet 1, list your competencies. Give each a short, unique code.', ''],
                    ['Step 2', 'On sheets 2, 3 and 4, add the child rows — putting the competency code in the first column of each.', ''],
                    ['Step 3', 'On sheet 3, put the rungs in order. The first row for a competency becomes level 1, the next level 2, and so on.', ''],
                    ['Step 4', 'Save the file and upload it in the Import Center under "Master Competency".', ''],
                ],
            ],
            [
                'heading' => 'THE COLUMNS',
                'columns' => ['Column', 'Required?', 'What to put in it'],
                'rows' => [
                    ['code (sheet 1)', 'Required', 'A short identifier, up to 50 characters — for example COMM. Unique, and how the row is matched.'],
                    ['competency_type (sheet 1)', 'Required', 'From "Ref - Competency Types" — its Code, or its Name if it has none.'],
                    ['name_en', 'Required', 'The English name. On sheet 1 it must be unique across all competencies.'],
                    ['name_id', 'Optional', 'The Indonesian name, shown when the app is set to Indonesian.'],
                    ['description_en / description_id', 'Optional', 'A sentence of explanation.'],
                    ['is_active', 'Optional', 'yes or no. Blank means yes.'],
                ],
            ],
            GuideSheet::goodToKnow(
                'A row is matched on its code first, then on name_en. Anything unmatched adds a new competency.',
                [
                    [
                        'Leaving a sheet empty',
                        'Nothing is deleted.',
                        'List no rungs for a competency and it keeps the ladder it already has. The same goes for sub competencies.',
                    ],
                    [
                        'Removing one child row',
                        'Leave it out, but keep its siblings listed.',
                        'The rows you do list replace what was stored. Clearing a list completely has to be done on the Master Competency screen.',
                    ],
                ],
            ),
        ]);
    }

    /**
     * The competency types on offer. `ref` is what a row should carry: the code
     * where the type has one, otherwise its English name — the importer accepts
     * either, which is what keeps the template usable while the types that
     * predate the code column still have none.
     *
     * @return list<array{ref: string, code: string, name: string, others: bool}>
     */
    private function types(): array
    {
        try {
            return CompetencyType::query()
                ->orderBy('name_en')
                ->get(['code', 'name_en'])
                ->map(fn (CompetencyType $type) => [
                    'ref' => (string) ($type->code ?: $type->name_en),
                    'code' => (string) ($type->code ?? ''),
                    'name' => (string) $type->name_en,
                    'others' => $type->isOthers(),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array{ref: string, code: string, name: string, others: bool}>  $types
     * @return list<list<string>>
     */
    private function reference(array $types): array
    {
        if ($types === []) {
            return [['', '', '(no competency type has been created yet)', '']];
        }

        $hint = 'Copy into the competency_type column on the Competency sheet.';

        return array_map(
            fn (array $type, int $index) => [
                $type['ref'],
                $type['code'] ?: '—',
                $type['name'],
                $index === 0 ? $hint : '',
            ],
            $types,
            array_keys($types),
        );
    }
}
