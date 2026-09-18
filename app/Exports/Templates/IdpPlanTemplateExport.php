<?php

namespace App\Exports\Templates;

use App\Models\Competency;
use App\Models\DevelopmentModel;
use App\Models\DevelopmentModelPackage;
use App\Models\ReviewTool;
use App\Services\Idp\IdpStageService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The starter workbook for the "Upload Development Plan" action on one
 * employee's IDP.
 *
 * It replaced a static file that had drifted badly: it still carried the
 * realization/evidence columns (a result is filed per program now, through the
 * result drawer, so a spreadsheet cannot set one), and its sample row named a
 * competency the master no longer has. Generating it means the columns are
 * always the ones the importer reads and the reference tabs always show what
 * the cascade will actually accept.
 *
 * It is a PLANNING template, scoped to ONE cycle — the active package, the only
 * one a plan can be written into.
 */
class IdpPlanTemplateExport implements WithMultipleSheets
{
    public function __construct(private readonly IdpStageService $stage) {}

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        $package = $this->stage->currentPackage();
        $models = $this->models($package);
        $combinations = $this->combinations($models);
        $sample = $combinations[0] ?? null;

        return [
            GuideSheet::make('DEVELOPMENT PLAN — IMPORT GUIDE', [
                [
                    'heading' => 'WHAT THIS FILE IS FOR',
                    'rows' => [
                        ['', 'Adding development programs to one employee\'s plan, for the cycle below.', ''],
                        ['', 'Cycle: '.($package?->name ?? 'no active cycle'), ''],
                        ['', 'Every row ADDS a program. Nothing here edits or removes one — do that on the screen.', ''],
                    ],
                ],
                [
                    'heading' => 'THE SHEETS IN THIS FILE',
                    'columns' => ['Sheet', 'Fill in or reference?', 'What it is for'],
                    'rows' => [
                        [
                            '1. Development Plan',
                            GuideSheet::IMPORTED,
                            'One row per program. This is the only sheet that is saved.',
                        ],
                        [
                            'Ref - Valid Combinations',
                            GuideSheet::REFERENCE,
                            'Every model + type + competency + program the system will accept for this cycle. Copy a row from here and it cannot be rejected.',
                        ],
                        [
                            'Ref - Review Tools',
                            GuideSheet::REFERENCE,
                            'The review tools you may name. Leave the column empty if none applies.',
                        ],
                    ],
                ],
                [
                    'heading' => 'THE RESULT IS NOT IN THIS FILE',
                    'rows' => [
                        ['', 'A plan says what WILL be done. The realization date and the evidence say what WAS done.', ''],
                        ['', 'Those are filed per program on the screen, because each one is approved on its own.', ''],
                        ['', 'An older template had realization_date and result_evidence columns. They are ignored now.', ''],
                    ],
                ],
                [
                    'heading' => 'HOW TO FILL IT IN',
                    'rows' => [
                        ['1.', 'Open "Ref - Valid Combinations" and find the program you want.', ''],
                        ['2.', 'Copy its four values into a row on "1. Development Plan".', ''],
                        ['3.', 'Add the start date, and the end date if there is one. Use DD-MM-YYYY.', ''],
                        ['4.', 'Save the file and upload it from the employee\'s IDP screen.', ''],
                    ],
                ],
                [
                    'heading' => 'THE COLUMNS',
                    'columns' => ['Column', 'Required?', 'What to put in it'],
                    'rows' => [
                        ['development_model', 'Required', 'A model of this cycle — its name, or its percentage (e.g. 70).'],
                        ['competency_type', 'Required', 'One of the configured competency types. Case does not matter.'],
                        ['competency_name', 'Required', 'An active competency filed under that type.'],
                        ['development_program', 'Required', 'A program linked to that competency, under that model.'],
                        ['review_tools', 'Optional', 'One of the review tools on the reference tab.'],
                        ['expected_outcome', 'Optional', 'What success looks like. Up to 500 characters.'],
                        ['time_frame_start', 'Required', 'DD-MM-YYYY.'],
                        ['time_frame_end', 'Optional', 'DD-MM-YYYY, not before the start date.'],
                    ],
                ],
                // NOT GuideSheet::goodToKnow(): the master imports upsert, so
                // theirs promises re-uploading is safe. This one APPENDS, and a
                // guide that said otherwise would cause the duplicates it denies.
                [
                    'heading' => 'GOOD TO KNOW',
                    'columns' => ['Topic', 'What happens', 'Why'],
                    'rows' => [
                        [
                            'Every row adds',
                            'There is no matching against what is already there.',
                            'The same file twice adds every program twice. Check the plan before re-uploading.',
                        ],
                        [
                            'A row with a problem',
                            'Only that row is skipped.',
                            'Every other row still imports, and the message names what was wrong with it.',
                        ],
                        [
                            'The plan needs approval again',
                            'Importing withdraws the approval.',
                            'The approver signed off a set of programs; adding to it makes a different set.',
                        ],
                        [
                            'While it is being approved',
                            'The upload is refused.',
                            'The set is frozen so it cannot change under the approver reading it.',
                        ],
                    ],
                ],
            ]),

            new ArraySheet(
                '1. Development Plan',
                [
                    'development_model', 'competency_type', 'competency_name', 'development_program',
                    'review_tools', 'expected_outcome', 'time_frame_start', 'time_frame_end',
                ],
                $sample === null ? [] : [[
                    $sample['model'],
                    $sample['type'],
                    $sample['competency'],
                    $sample['program'],
                    ReviewTool::active()->value('name_en'),
                    'Applies the competency independently in day-to-day work.',
                    now()->startOfYear()->format('d-m-Y'),
                    now()->endOfYear()->format('d-m-Y'),
                ]],
                ['D' => 46, 'F' => 40],
            ),

            new ArraySheet(
                'Ref - Valid Combinations',
                ['development_model', 'competency_type', 'competency_name', 'development_program'],
                array_map(
                    fn (array $c) => [$c['model'], $c['type'], $c['competency'], $c['program']],
                    $combinations,
                ) ?: [['No development program is configured for this cycle yet.', '', '', '']],
                ['D' => 60],
            ),

            new ArraySheet(
                'Ref - Review Tools',
                ['review_tools'],
                ReviewTool::active()->orderBy('name_en')->pluck('name_en')
                    ->map(fn ($name) => [$name])->all()
                    ?: [['No active review tool.']],
            ),
        ];
    }

    /**
     * This cycle's development models, heaviest first — the order the screen
     * lists them in.
     *
     * @return Collection<int, DevelopmentModel>
     */
    private function models(?DevelopmentModelPackage $package)
    {
        return DevelopmentModel::when(
            $package,
            fn ($q) => $q->where('development_model_package_id', $package->id),
            fn ($q) => $q->whereRaw('1 = 0'),
        )->orderByDesc('percentage')->orderBy('name')->get();
    }

    /**
     * Every model + type + competency + program the cascade would accept, which
     * is what makes the reference tab worth copying from: a row taken off it
     * cannot be rejected for fitting the master data badly.
     *
     * Mirrors PlanMasterRules: a competency is scoped strictly by its type,
     * while a program with no type or no model of its own counts as global.
     *
     * @param  Collection<int, DevelopmentModel>  $models
     * @return list<array{model: string, type: string, competency: string, program: string}>
     */
    private function combinations($models): array
    {
        $competencies = Competency::with([
            'competencyType:id,name_en',
            'developmentPrograms:id,name_en,development_model_id,competency_type_id',
            'developmentPrograms.competencyType:id,name_en',
        ])->active()->orderBy('name_en')->get();

        $rows = [];

        foreach ($models as $model) {
            foreach ($competencies as $competency) {
                $type = trim((string) $competency->competencyType?->name_en);

                // An untyped competency is legacy data and belongs under no
                // type, so it could never be picked.
                if ($type === '') {
                    continue;
                }

                foreach ($competency->developmentPrograms as $program) {
                    $programModel = $program->development_model_id;
                    $programType = trim((string) $program->competencyType?->name_en);

                    if ($programModel !== null && $programModel !== $model->id) {
                        continue;
                    }

                    if ($programType !== '' && strcasecmp($programType, $type) !== 0) {
                        continue;
                    }

                    $rows[] = [
                        'model' => $model->name,
                        'type' => $type,
                        'competency' => $competency->name_en,
                        'program' => $program->name_en,
                    ];
                }
            }
        }

        return $rows;
    }
}
