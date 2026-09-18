<?php

namespace Database\Seeders\Demo;

use App\Models\CompetencyAssessment;
use App\Models\DevelopmentProgram;
use App\Models\Employee;
use App\Models\IndividualDevelopmentPlan;
use App\Models\PerformanceAppraisal;
use App\Models\ResultSummary;
use App\Models\ReviewTool;
use App\Services\MatrixGradeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Per-employee talent data built on top of the corporate employee master:
 * competency assessments (with the matrix grade derived the same way the app
 * derives it), succession summaries, the nine-box appraisal rows and the
 * individual development plans.
 *
 * Every plan names its competency type, competency, development programme and
 * review tool VERBATIM, taken from rows that actually exist in the master data
 * -- which is what makes the IDP screen's cascade resolve them instead of
 * flagging them as legacy free text.
 *
 * Deterministic (no randomness) and idempotent, so a re-run produces exactly
 * the same data rather than piling more on.
 */
class TalentDemoSeeder extends Seeder
{
    private const TALENT_BOXES = [
        'Stars (1)', 'High Potentials (2)', 'High Impact Performers (3)',
        'Trusted Professional (4)', 'Potential Gems (5)', 'Core Players (6)',
        'Effective Employee (7)', 'Inconsistent Performers (8)', 'Deadwood (9)',
    ];

    private const SCORE_FIELDS = [
        'synergized_team_score', 'integrity_score', 'growth_score', 'adaptive_score',
        'passion_score', 'manage_planning_score', 'decision_making_score',
        'relationship_building_score', 'developing_others_score',
    ];

    private const EXPECTED_OUTCOMES = [
        'Able to apply the competency independently on day-to-day work by the end of the period.',
        'Demonstrates the target proficiency level consistently, evidenced in the quarterly review.',
        'Leads at least one initiative that shows the competency in practice.',
        'Receives a rating of 3 or above from the superior on this competency.',
        'Can coach a peer through the same task without support.',
    ];

    private const EVIDENCE = [
        'Project closing report submitted and accepted by the department head.',
        'Certificate of completion attached; scored above the passing mark.',
        'Superior review recorded in the quarterly performance conversation.',
        'Presentation deck and meeting minutes filed on the shared drive.',
    ];

    /**
     * How many of the employees get a full IDP. Every one of them, so the HC
     * Report's IDP-progress column reads as a real progress spread instead of
     * mostly "0/0".
     */
    private const EMPLOYEES_WITH_IDP = PHP_INT_MAX;

    /** Plans per employee that gets one. */
    private const PLANS_PER_EMPLOYEE = 3;

    public function __construct(private readonly MatrixGradeService $matrix) {}

    public function run(): void
    {
        $employees = $this->employees();

        if ($employees->isEmpty()) {
            $this->command?->warn('  no employees on kpncorp -- talent data skipped.');

            return;
        }

        $this->seedAssessments($employees);
        $this->seedResultSummaries($employees);
        $this->seedAppraisals($employees);
        $this->seedPlans($employees);

        $this->command?->info('  assessments: '.CompetencyAssessment::count()
            .' | succession: '.ResultSummary::count()
            .' | nine-box: '.PerformanceAppraisal::count()
            .' | IDP plans: '.IndividualDevelopmentPlan::count());
    }

    /**
     * @return Collection<int, Employee>
     */
    private function employees(): Collection
    {
        try {
            return Employee::query()
                ->whereNotNull('employee_id')
                ->where('employee_id', '<>', '')
                ->orderBy('employee_id')
                ->get(['employee_id', 'fullname', 'job_level', 'designation_name', 'group_company', 'manager_l1_id', 'manager_l2_id']);
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * A competency assessment for both periods, with the matrix grade derived
     * by the same service the scoring form uses.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function seedAssessments(Collection $employees): void
    {
        foreach ($employees->values() as $i => $employee) {
            foreach ([2025, 2026] as $offset => $period) {
                $scores = $this->scoresFor($i + $offset);

                CompetencyAssessment::updateOrCreate(
                    ['employee_id' => $employee->employee_id, 'period' => (string) $period],
                    array_merge($scores, [
                        'assessment_date' => $period.'-03-'.str_pad((string) (($i % 27) + 1), 2, '0', STR_PAD_LEFT),
                        'proposed_grade' => $employee->job_level ?: '5A',
                        'priority_for_development' => $i % 3 === 0 ? 'Yes' : 'No',
                        'matrix_grade' => $this->matrix->calculate($scores, $period),
                    ]),
                );
            }
        }
    }

    /**
     * Succession data for roughly half the population -- a critical-position
     * flag and a readiness band.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function seedResultSummaries(Collection $employees): void
    {
        $readiness = ['Ready Now', 'Ready 1-2 Years', 'Ready 3-5 Years'];

        foreach ($employees->values() as $i => $employee) {
            if ($i % 2 !== 0) {
                continue;
            }

            ResultSummary::updateOrCreate(
                ['employee_id' => $employee->employee_id],
                [
                    'critical_position' => $i % 6 === 0 ? 'Yes' : 'No',
                    'successor_type' => $readiness[$i % 3],
                    'successor_to_position' => $employee->designation_name ?: 'Department Head',
                ],
            );
        }
    }

    /**
     * The nine-box rows. These live on the CORPORATE connection: the grade is
     * corporate data and the app adds the potential + talent box mapping.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function seedAppraisals(Collection $employees): void
    {
        $grades = ['A', 'B', 'C'];
        $potentials = ['High', 'Medium', 'Low'];

        foreach ($employees->values() as $i => $employee) {
            foreach ([2025, 2026] as $offset => $year) {
                // Walking performance one step and potential every third step
                // covers all nine boxes across the population, rather than the
                // three-box diagonal a shared counter would produce.
                $performance = ($i + $offset) % 3;          // 0 = A (best)
                $potential = intdiv($i + $offset, 3) % 3;   // 0 = High

                try {
                    PerformanceAppraisal::updateOrCreate(
                        ['employee_id' => $employee->employee_id, 'appraisal_year' => $year],
                        [
                            'grade' => $grades[$performance],
                            'potential' => $potentials[$potential],
                            // The 3x3 grid reads potential down, performance across.
                            'talent_box' => self::TALENT_BOXES[$potential * 3 + $performance],
                        ],
                    );
                } catch (\Throwable $e) {
                    $this->command?->warn('  nine-box write refused on kpncorp: '.$e->getMessage());

                    return;
                }
            }
        }
    }

    /**
     * Individual development plans, drawn from programmes that really exist so
     * the IDP cascade resolves every stored name.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function seedPlans(Collection $employees): void
    {
        $options = $this->planOptions();

        if ($options === []) {
            $this->command?->warn('  no development programmes -- IDP plans skipped.');

            return;
        }

        $tools = ReviewTool::where('is_active', true)->orderBy('id')->pluck('name_en')->all();

        if ($tools === []) {
            $tools = ['Superior Rating'];
        }

        // One plan per development model, so an employee's IDP spans the whole
        // 70-20-10 split the way a real one does, rather than stacking three
        // items under whichever model happened to come first.
        $byModel = array_values(collect($options)->groupBy('model_id')->map->all()->all());

        foreach ($employees->take(self::EMPLOYEES_WITH_IDP)->values() as $i => $employee) {
            for ($n = 0; $n < self::PLANS_PER_EMPLOYEE; $n++) {
                $pool = $byModel[$n % count($byModel)];
                $option = $pool[($i + $n) % count($pool)];

                // How many of this employee's items are finished (a realization
                // date and evidence, which is what makes an item submittable
                // for approval). Cycling 0..3 gives the HC Report's progress
                // column the full range rather than one value for everybody.
                $completed = $n < ($i % (self::PLANS_PER_EMPLOYEE + 1));

                IndividualDevelopmentPlan::updateOrCreate(
                    [
                        'employee_id' => $employee->employee_id,
                        'development_model_id' => $option['model_id'],
                        'competency_name' => $option['competency'],
                    ],
                    [
                        'competency_type' => $option['type'],
                        'development_program' => $option['program'],
                        'review_tools' => $tools[($i + $n) % count($tools)],
                        'expected_outcome' => self::EXPECTED_OUTCOMES[($i + $n) % count(self::EXPECTED_OUTCOMES)],
                        'time_frame_start' => '2026-01-01',
                        'time_frame_end' => '2026-12-31',
                        'realization_date' => $completed ? '2026-06-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT) : null,
                        'result_evidence' => $completed ? self::EVIDENCE[($i + $n) % count(self::EVIDENCE)] : null,
                    ],
                );
            }
        }
    }

    /**
     * Every (model, competency type, competency, programme) combination the
     * master data actually offers -- the same set the plan drawer's cascade
     * would present.
     *
     * @return list<array{model_id:int, type:string, competency:string, program:string}>
     */
    private function planOptions(): array
    {
        $options = [];

        $programs = DevelopmentProgram::query()
            ->whereNotNull('development_model_id')
            ->with(['competencies.competencyType'])
            ->orderBy('development_model_id')
            ->orderBy('id')
            ->get();

        foreach ($programs as $program) {
            foreach ($program->competencies as $competency) {
                $type = $competency->competencyType?->name_en;

                if (blank($type)) {
                    continue;
                }

                $options[] = [
                    'model_id' => (int) $program->development_model_id,
                    'type' => $type,
                    'competency' => (string) $competency->name_en,
                    'program' => (string) $program->name_en,
                ];
            }
        }

        return $options;
    }

    /**
     * A spread of the nine competency scores (0-4) that varies per employee but
     * is stable for a given one, so a re-run does not reshuffle the grades.
     *
     * The matrix grade is driven by the LOWEST of the nine scores (a config is
     * only met when every minimum is), so the floor is cycled 0-4 across the
     * population to produce the full range of grades rather than a single one.
     *
     * @return array<string, int>
     */
    private function scoresFor(int $i): array
    {
        $floor = $i % 5;              // 0..4 -- what the grade ends up keyed on
        $headroom = ($i % 3 === 0) ? 1 : 0;
        $scores = [];

        foreach (self::SCORE_FIELDS as $n => $field) {
            // One field sits on the floor; the rest sit at or just above it, so
            // the row reads like a real assessment rather than a flat line.
            $lift = $n === ($i % count(self::SCORE_FIELDS)) ? 0 : (($i + $n) % 2) + $headroom;

            $scores[$field] = max(0, min(4, $floor + $lift));
        }

        return $scores;
    }
}
