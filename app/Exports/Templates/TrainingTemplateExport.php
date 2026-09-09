<?php

namespace App\Exports\Templates;

use App\Models\Competency;
use App\Services\CorporateScopeService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The starter workbook for a Master Training import.
 *
 * One sheet to fill in — a training is one row — plus three reference sheets,
 * because every scope column is checked against a master: the competency and
 * its rungs, the corporate business units, and the work locations belonging to
 * each unit. Those are exactly the values a filler would otherwise guess at.
 *
 * The samples are built from live data, so the workbook is uploadable as-is in
 * whatever environment it was downloaded from.
 */
class TrainingTemplateExport implements WithMultipleSheets
{
    public function __construct(private readonly CorporateScopeService $corporate) {}

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        $competencies = $this->competencies();
        $locations = $this->corporate->workLocations();

        return [
            $this->guide(),

            new ArraySheet(
                '1. Master Training',
                [
                    'name_en', 'name_id', 'description_en', 'description_id',
                    'competency_type', 'competency', 'proficiency_levels',
                    'business_units', 'work_locations', 'is_active',
                ],
                $this->samples($competencies, $locations['byBusinessUnit']),
            ),

            new ArraySheet(
                'Ref - Competencies',
                ['Competency type', 'Competency', 'Competency code', 'Its proficiency levels', 'Active'],
                $this->competencyReference($competencies),
                ['D' => 46],
            ),

            new ArraySheet(
                'Ref - Business Units',
                ['Business unit'],
                array_map(fn (string $unit) => [$unit], $locations['businessUnits']),
            ),

            new ArraySheet(
                'Ref - Work Locations',
                ['Business unit', 'Work location'],
                $this->locationReference($locations['byBusinessUnit']),
            ),
        ];
    }

    /**
     * The opening sheet: which tab is saved, which are only there to copy
     * from, and what each column expects.
     */
    private function guide(): ArraySheet
    {
        return GuideSheet::make('MASTER TRAINING — IMPORT GUIDE', [
            [
                'heading' => 'WHAT THIS FILE IS FOR',
                'rows' => [
                    ['', 'Adding master trainings, or editing the ones you already have.', ''],
                    ['', 'A training is one row. What it develops and who it is for are all on that row — several values go in a single cell, separated by a semicolon.', ''],
                ],
            ],
            [
                'heading' => 'THE SHEETS IN THIS FILE',
                'columns' => ['Sheet', 'Fill in or reference?', 'What it is for'],
                'rows' => [
                    [
                        '1. Master Training',
                        GuideSheet::IMPORTED,
                        'One row per training. This is the only sheet that is saved.',
                    ],
                    [
                        'Ref - Competencies',
                        GuideSheet::REFERENCE,
                        'Every competency, with its type and its own proficiency levels. This is where the first three scope columns come from.',
                    ],
                    [
                        'Ref - Business Units',
                        GuideSheet::REFERENCE,
                        'The company business units.',
                    ],
                    [
                        'Ref - Work Locations',
                        GuideSheet::REFERENCE,
                        'Every site, listed under the business unit it belongs to.',
                    ],
                ],
            ],
            [
                'heading' => 'HOW TO FILL IT IN',
                'columns' => ['Step', 'Do this', ''],
                'rows' => [
                    ['Step 1', 'Name the training in name_en.', ''],
                    ['Step 2', 'On "Ref - Competencies", find the competency this training builds. Copy its type and the competency itself onto your row.', ''],
                    ['Step 3', 'From the same reference row, copy the proficiency levels you want to target — they must belong to that competency.', ''],
                    ['Step 4', 'If the training is only offered in certain places, copy the units from "Ref - Business Units" and the sites from "Ref - Work Locations".', ''],
                    ['Step 5', 'Save the file and upload it in the Import Center under "Master Training".', ''],
                ],
            ],
            [
                'heading' => 'THE COLUMNS',
                'columns' => ['Column', 'Required?', 'What to put in it'],
                'rows' => [
                    ['name_en', 'Required', 'The English name. This is also how the row is matched.'],
                    ['name_id', 'Optional', 'The Indonesian name.'],
                    ['description_en / description_id', 'Optional', 'A sentence describing the training.'],
                    ['competency_type', 'Required', 'From "Ref - Competencies" — its Code, or its Name if it has none.'],
                    ['competency', 'Required', 'The competency this training builds. It must be filed under the type above.'],
                    [
                        'proficiency_levels',
                        'Optional',
                        'Which rungs of that competency the training targets. Several in one cell, separated by a semicolon — for example: Basic; Intermediate',
                    ],
                    ['business_units', 'Optional', 'Several in one cell, separated by a semicolon.'],
                    ['work_locations', 'Optional', 'Several in one cell, separated by a semicolon. Each site must belong to one of the business units on the same row.'],
                    ['is_active', 'Optional', 'yes or no. Blank means yes.'],
                ],
            ],
            GuideSheet::goodToKnow(
                'A row with a name that already exists edits that training. Any other name adds a new one.',
                [
                    [
                        'Leaving a list cell empty',
                        'Nothing is deleted.',
                        'The training keeps the levels, units or sites it already has. To shorten a list, type the values you want to keep.',
                    ],
                    [
                        'Renaming a training',
                        'Not possible here — you get a second training.',
                        'A training has no code, so its name is its identity. Rename it on the Master Training screen instead.',
                    ],
                ],
            ),
        ]);
    }

    /**
     * Two rows: one fully scoped, one with the scope left off, since every part
     * of it but the competency is optional.
     *
     * @param  list<array<string, mixed>>  $competencies
     * @param  array<string, list<string>>  $byBusinessUnit
     * @return list<list<string>>
     */
    private function samples(array $competencies, array $byBusinessUnit): array
    {
        $usable = collect($competencies)->firstWhere('levels', '!=', []) ?? ($competencies[0] ?? null);

        $type = $usable['type'] ?? 'Soft Competency';
        $competency = $usable['ref'] ?? 'Communication';
        $levels = implode('; ', array_slice($usable['levels'] ?? [], 0, 2));

        // A unit that actually has sites, so the sample's locations are real.
        $unit = collect($byBusinessUnit)->filter()->keys()->first()
            ?? (array_key_first($byBusinessUnit) ?: 'KPN Corporation');
        $sites = implode('; ', array_slice($byBusinessUnit[$unit] ?? [], 0, 2));

        return [
            [
                'Effective Communication Workshop',
                'Lokakarya Komunikasi Efektif',
                'Two-day workshop on getting a message across clearly.',
                'Lokakarya dua hari tentang menyampaikan pesan dengan jelas.',
                $type, $competency, $levels, $unit, $sites, 'yes',
            ],
            [
                'Problem Solving Fundamentals',
                'Dasar Pemecahan Masalah',
                'Self-paced introduction, open to everyone.',
                'Pengenalan mandiri, terbuka untuk semua.',
                $type, $competency, '', '', '', 'yes',
            ],
        ];
    }

    /**
     * Every competency with the rungs a training may target, so the two scope
     * columns can be filled without opening the app.
     *
     * @return list<array<string, mixed>>
     */
    private function competencies(): array
    {
        try {
            return Competency::with(['competencyType:id,code,name_en', 'proficiencyLevels'])
                ->orderBy('name_en')
                ->get()
                ->map(fn (Competency $c) => [
                    'type' => (string) ($c->competencyType?->code ?: $c->competencyType?->name_en ?? ''),
                    'typeName' => (string) ($c->competencyType?->name_en ?? ''),
                    'ref' => (string) ($c->code ?: $c->name_en),
                    'name' => (string) $c->name_en,
                    'code' => (string) ($c->code ?? ''),
                    'active' => $c->is_active,
                    'levels' => $c->proficiencyLevels->pluck('name_en')->map(fn ($n) => (string) $n)->all(),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $competencies
     * @return list<list<string>>
     */
    private function competencyReference(array $competencies): array
    {
        if ($competencies === []) {
            return [['', '(no competency has been created yet)', '', '', '']];
        }

        return array_map(fn (array $c) => [
            $c['typeName'],
            $c['name'],
            $c['code'] ?: '—',
            implode('; ', $c['levels']) ?: '(no proficiency levels yet)',
            $c['active'] ? 'yes' : 'no',
        ], $competencies);
    }

    /**
     * @param  array<string, list<string>>  $byBusinessUnit
     * @return list<list<string>>
     */
    private function locationReference(array $byBusinessUnit): array
    {
        $rows = [];

        foreach ($byBusinessUnit as $unit => $areas) {
            foreach ($areas as $area) {
                $rows[] = [$unit, $area];
            }
        }

        return $rows === [] ? [['', '(no corporate work locations could be read)']] : $rows;
    }
}
