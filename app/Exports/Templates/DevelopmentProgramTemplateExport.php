<?php

namespace App\Exports\Templates;

use App\Models\Competency;
use App\Models\CompetencyImplementation;
use App\Models\DevelopmentModel;
use App\Models\Training;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The starter workbook for a Master Development (development program) import.
 *
 * One sheet to fill in — a program is one row — and three reference sheets that
 * between them answer the three questions this screen makes hard:
 *
 *  - which development model, in which package, and does it name its programs
 *    from Master Training?
 *  - which competency + proficiency level + grades has actually been
 *    implemented? (A program can only target what the implementation map
 *    covers, so this is not optional reading.)
 *  - which trainings can a master-training model draw on?
 *
 * The samples are built from live data, so the workbook is uploadable as-is in
 * whatever environment it was downloaded from.
 */
class DevelopmentProgramTemplateExport implements WithMultipleSheets
{
    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        $models = $this->models();
        $scopes = $this->scopes();
        $trainings = $this->trainings();

        return [
            $this->guide(),

            new ArraySheet(
                '1. Master Development',
                [
                    'model_package', 'development_model', 'competency_type', 'competency',
                    'name_en', 'name_id', 'description_en', 'description_id',
                    'training', 'proficiency_level', 'custom_proficiency_level', 'grades',
                ],
                $this->samples($models, $scopes, $trainings),
            ),

            new ArraySheet(
                'Ref - Development Models',
                ['Package', 'Development model', 'Weight', 'Names from Master Training?'],
                $this->modelReference($models),
                ['B' => 40],
            ),

            new ArraySheet(
                'Ref - Implemented Scope',
                ['Competency type', 'Competency', 'Proficiency level', 'Grades covered'],
                $this->scopeReference($scopes),
                ['D' => 40],
            ),

            new ArraySheet(
                'Ref - Trainings',
                ['Master training', 'Active'],
                $trainings === []
                    ? [['(no master training has been created yet)', '']]
                    : array_map(fn (array $t) => [$t['name'], $t['active'] ? 'yes' : 'no'], $trainings),
                ['A' => 52],
            ),
        ];
    }

    /**
     * The opening sheet. This screen is the one where a row meaning depends on
     * things elsewhere — the model decides where the name comes from, the
     * implementation map decides what level and grades are allowed — so the
     * guide leads with that.
     */
    private function guide(): ArraySheet
    {
        return GuideSheet::make('MASTER DEVELOPMENT — IMPORT GUIDE', [
            [
                'heading' => 'WHAT THIS FILE IS FOR',
                'rows' => [
                    ['', 'Adding development programs, or editing the ones you already have.', ''],
                    ['', 'A development program is one row: the activity, the development model it sits under, and the competency it builds.', ''],
                ],
            ],
            [
                'heading' => 'THE SHEETS IN THIS FILE',
                'columns' => ['Sheet', 'Fill in or reference?', 'What it is for'],
                'rows' => [
                    [
                        '1. Master Development',
                        GuideSheet::IMPORTED,
                        'One row per development program. This is the only sheet that is saved.',
                    ],
                    [
                        'Ref - Development Models',
                        GuideSheet::REFERENCE,
                        'The models, by package — and whether each one takes its program names from Master Training. Read that column before you fill anything in.',
                    ],
                    [
                        'Ref - Implemented Scope',
                        GuideSheet::REFERENCE,
                        'What Master Implementation has actually rolled out: which competency, at which proficiency level, for which grades. A program can only target what is listed here.',
                    ],
                    [
                        'Ref - Trainings',
                        GuideSheet::REFERENCE,
                        'The master trainings, for the models that take their names from the catalogue.',
                    ],
                ],
            ],
            [
                'heading' => 'THE ONE THING TO CHECK FIRST',
                'columns' => ['If the model says...', 'Then...', ''],
                'rows' => [
                    [
                        'no — type the name',
                        'Type name_en yourself, and leave the training column empty.',
                        '',
                    ],
                    [
                        'yes — give a training',
                        'Put a training name in the training column and leave name_en empty. The name and description are copied from the training, and anything you type is ignored.',
                        '',
                    ],
                ],
            ],
            [
                'heading' => 'HOW TO FILL IT IN',
                'columns' => ['Step', 'Do this', ''],
                'rows' => [
                    ['Step 1', 'On "Ref - Development Models", pick the model. Copy its package and name onto your row.', ''],
                    ['Step 2', 'Check that model last column, and name the program the way it says (see above).', ''],
                    ['Step 3', 'On "Ref - Implemented Scope", find the competency this program builds. Copy its type, the competency, and — if you want one — a proficiency level from the same reference row.', ''],
                    ['Step 4', 'For grades, use only what that same reference row lists as covered. Leave it blank for every grade.', ''],
                    ['Step 5', 'Save the file and upload it in the Import Center under "Master Development".', ''],
                ],
            ],
            [
                'heading' => 'THE COLUMNS',
                'columns' => ['Column', 'Required?', 'What to put in it'],
                'rows' => [
                    ['model_package', 'Required', 'Which package the model belongs to. Two packages can hold a model of the same name, which is what this tells apart.'],
                    ['development_model', 'Required', 'The model name, from "Ref - Development Models".'],
                    ['competency_type', 'Required', 'Its Code, or its Name if it has none.'],
                    ['competency', 'Required', 'The single competency this program builds.'],
                    ['name_en', 'Usually required', 'The activity, written out — it may run long. Not needed under a model that names its programs from Master Training.'],
                    ['name_id', 'Optional', 'The Indonesian name.'],
                    ['description_en / description_id', 'Optional', 'A sentence of explanation.'],
                    ['training', 'Sometimes required', 'Only for a model that says "yes — give a training". Otherwise leave it blank.'],
                    ['proficiency_level', 'Optional', 'A rung listed for that competency on "Ref - Implemented Scope".'],
                    ['custom_proficiency_level', 'Optional', 'Only for a competency filed under the catch-all "Others" type, which has no implementation map to draw a level from.'],
                    ['grades', 'Optional', 'Several in one cell, separated by a semicolon — for example: 1A; 2C. Blank means every grade.'],
                ],
            ],
            GuideSheet::goodToKnow(
                'A row is matched by its name within its development model. The same name under a different model is a different program.',
                [
                    [
                        'Leaving grades empty',
                        'Nothing is deleted.',
                        'The program keeps the grades it already has. On a new program it means every grade.',
                    ],
                    [
                        'Nothing on "Ref - Implemented Scope"',
                        'No program can target a proficiency level yet.',
                        'Map the competency on the Master Implementation screen first, then download this template again.',
                    ],
                ],
            ),
        ]);
    }

    /**
     * Two rows: one typed by hand, and — where a master-training model and a
     * training both exist — one drawing its name from the catalogue.
     *
     * @param  list<array<string, mixed>>  $models
     * @param  list<array<string, mixed>>  $scopes
     * @param  list<array<string, mixed>>  $trainings
     * @return list<list<string>>
     */
    private function samples(array $models, array $scopes, array $trainings): array
    {
        $typed = collect($models)->firstWhere('training', false) ?? ($models[0] ?? null);
        $fromTraining = collect($models)->firstWhere('training', true);

        // A competency + level + grades the implementation map actually covers,
        // so the sample passes the scope check rather than merely looking right.
        $scope = $scopes[0] ?? null;

        $rows = [[
            $typed['package'] ?? 'Model 2026',
            $typed['name'] ?? 'On The Job Training/Assignment',
            $scope['type'] ?? 'Soft Competency',
            $scope['competencyRef'] ?? 'Communication',
            'Shadow a senior colleague for one full project cycle',
            'Mendampingi rekan senior selama satu siklus proyek penuh',
            'Learning on the job, with the work itself as the exercise.',
            'Belajar sambil bekerja, dengan pekerjaan itu sendiri sebagai latihannya.',
            '',
            $scope['level'] ?? '',
            '',
            implode('; ', array_slice($scope['grades'] ?? [], 0, 2)),
        ]];

        if ($fromTraining !== null && $trainings !== []) {
            $rows[] = [
                $fromTraining['package'],
                $fromTraining['name'],
                $scope['type'] ?? 'Soft Competency',
                $scope['competencyRef'] ?? 'Communication',
                '(taken from the training)',
                '',
                '',
                '',
                $trainings[0]['name'],
                $scope['level'] ?? '',
                '',
                '',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function models(): array
    {
        try {
            return DevelopmentModel::with('developmentModelPackage')
                ->orderBy('development_model_package_id')
                ->orderBy('id')
                ->get()
                ->map(fn (DevelopmentModel $m) => [
                    'package' => (string) ($m->developmentModelPackage?->name ?? ''),
                    'name' => (string) $m->name_en,
                    'percentage' => (string) $m->percentage,
                    'training' => (bool) $m->uses_master_training,
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * What the master implementation map actually covers, flattened to one row
     * per (competency, proficiency level).
     *
     * @return list<array<string, mixed>>
     */
    private function scopes(): array
    {
        try {
            $competencies = Competency::with(['competencyType:id,code,name_en', 'proficiencyLevels'])
                ->get()->keyBy('id');

            $rows = [];

            foreach (CompetencyImplementation::with(['proficiencyLevels:id,name_en', 'grades'])->get() as $impl) {
                $competency = $competencies->get($impl->competency_id);

                if ($competency === null) {
                    continue;
                }

                foreach ($impl->proficiencyLevels as $level) {
                    $rows[] = [
                        'type' => (string) ($competency->competencyType?->code ?: $competency->competencyType?->name_en ?? ''),
                        'typeName' => (string) ($competency->competencyType?->name_en ?? ''),
                        'competencyRef' => (string) ($competency->code ?: $competency->name_en),
                        'competency' => (string) $competency->name_en,
                        'level' => (string) $level->name_en,
                        'grades' => $impl->grades->pluck('grade')->map(fn ($g) => (string) $g)->all(),
                    ];
                }
            }

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trainings(): array
    {
        try {
            return Training::orderBy('name_en')->get()
                ->map(fn (Training $t) => ['name' => (string) $t->name_en, 'active' => (bool) $t->is_active])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $models
     * @return list<list<string>>
     */
    private function modelReference(array $models): array
    {
        if ($models === []) {
            return [['', '(no development model has been created yet)', '', '']];
        }

        return array_map(fn (array $m) => [
            $m['package'],
            $m['name'],
            $m['percentage'].'%',
            $m['training'] ? 'yes — give a training, the name comes from it' : 'no — type the name',
        ], $models);
    }

    /**
     * @param  list<array<string, mixed>>  $scopes
     * @return list<list<string>>
     */
    private function scopeReference(array $scopes): array
    {
        if ($scopes === []) {
            return [['', '(nothing has been mapped on Master Implementation yet — '
                .'no program can target a proficiency level until it is)', '', '']];
        }

        return array_map(fn (array $s) => [
            $s['typeName'],
            $s['competency'],
            $s['level'],
            implode('; ', $s['grades']) ?: 'every grade',
        ], $scopes);
    }
}
