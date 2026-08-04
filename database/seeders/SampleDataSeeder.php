<?php

namespace Database\Seeders;

use App\Models\CompetencyAssessment;
use App\Models\DevelopmentModel;
use App\Models\DevelopmentPlanMaster;
use App\Models\Employee;
use App\Models\IndividualDevelopmentPlan;
use App\Models\PerformanceAppraisal;
use App\Models\ResultSummary;
use App\Models\User;
use App\Services\MatrixGradeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * App-owned sample data (mysql) built on top of the seeded kpncorp employees:
 * users + roles, IDP master data, and per-employee IDPs / competency assessments
 * / succession summaries / 9-box. Skips gracefully if no employees are present.
 */
class SampleDataSeeder extends Seeder
{
    /** Named accounts that should be Superadmin (by employee_id). */
    private const SUPERADMINS = ['01124090037', '01124040023'];

    private const TALENT_BOXES = [
        'Stars (1)', 'High Potentials (2)', 'High Impact Performers (3)',
        'Trusted Professional (4)', 'Potential Gems (5)', 'Core Players (6)',
        'Effective Employee (7)', 'Inconsistent Performers (8)', 'Deadwood (9)',
    ];

    public function __construct(private readonly MatrixGradeService $matrix)
    {
    }

    public function run(): void
    {
        $employees = $this->sampleEmployees();

        if ($employees->isEmpty()) {
            $this->command?->warn('No employees found — run EmployeeSampleSeeder first. Skipping sample data.');

            return;
        }

        $this->seedMasterData();
        $this->seedUsers($employees);
        $this->seedTalentData($employees);

        $this->command?->info('Seeded app-owned sample data for '.$employees->count().' employees.');
    }

    /**
     * The named superadmins plus a spread of other employees (managers included).
     */
    private function sampleEmployees()
    {
        try {
            $named = Employee::whereIn('employee_id', self::SUPERADMINS)->get();
            $others = Employee::whereNotIn('employee_id', self::SUPERADMINS)
                ->orderBy('fullname')->limit(25)->get();

            return $named->concat($others)->unique('employee_id')->values();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    private function seedMasterData(): void
    {
        $models = DevelopmentModel::pluck('id', 'name');
        $otj = $models['On The Job Training/Assignment'] ?? null;
        $coaching = $models['Coaching and/or Mentoring'] ?? null;
        $formal = $models['Formal Learning (Including Training)'] ?? null;

        foreach (['Self Assessment', 'Manager Review', '360 Feedback'] as $tool) {
            DevelopmentPlanMaster::updateOrCreate(['type' => 'review_tools', 'value' => $tool, 'development_model_id' => null]);
        }

        $programDefs = [
            ['Stretch Assignment', $otj],
            ['Job Rotation', $otj],
            ['Mentoring Session', $coaching],
            ['Executive Coaching', $coaching],
            ['Leadership Training', $formal],
            ['Technical Certification', $formal],
        ];

        $programIds = [];
        foreach ($programDefs as [$value, $modelId]) {
            $program = DevelopmentPlanMaster::updateOrCreate(
                ['type' => 'development_program', 'value' => $value, 'development_model_id' => $modelId],
            );
            $programIds[$value] = (string) $program->id;
        }

        $competencyDefs = [
            'Leadership' => ['Stretch Assignment', 'Mentoring Session', 'Leadership Training'],
            'Communication' => ['Job Rotation', 'Executive Coaching'],
            'Technical Expertise' => ['Technical Certification', 'Stretch Assignment'],
            'Problem Solving' => ['Job Rotation', 'Leadership Training'],
        ];

        foreach ($competencyDefs as $competency => $programs) {
            DevelopmentPlanMaster::updateOrCreate(
                ['type' => 'competency_name', 'value' => $competency, 'development_model_id' => null],
                ['related_program' => array_values(array_map(fn ($p) => $programIds[$p], $programs))],
            );
        }
    }

    private function seedUsers($employees): void
    {
        foreach ($employees as $i => $employee) {
            $user = User::updateOrCreate(
                ['employee_id' => $employee->employee_id],
                [
                    'name' => $employee->fullname,
                    'email' => strtolower(str_replace(' ', '.', $employee->employee_id)).'@kpn.test',
                    'password' => Hash::make('password'),
                ],
            );

            if (in_array($employee->employee_id, self::SUPERADMINS, true)) {
                $user->syncRoles(['Superadmin']);
            } elseif ($i % 5 === 0) {
                $user->syncRoles(['Admin']);
            }
            // The rest stay role-less: managers are resolved structurally by the
            // scope service; plain employees see only themselves.
        }
    }

    private function seedTalentData($employees): void
    {
        $models = DevelopmentModel::orderBy('id')->get();
        $competencies = ['Leadership', 'Communication', 'Technical Expertise', 'Problem Solving'];
        $programs = ['Leadership Training', 'Mentoring Session', 'Job Rotation', 'Technical Certification'];

        foreach ($employees as $i => $employee) {
            $eid = $employee->employee_id;

            // Competency assessment (2026) with a derived matrix grade.
            $scores = $this->scoresFor($i);
            CompetencyAssessment::updateOrCreate(
                ['employee_id' => $eid, 'period' => 2026],
                array_merge($scores, [
                    'assessment_date' => '2026-03-15',
                    'proposed_grade' => ['5A', '5B', '6A', '4B'][$i % 4],
                    'priority_for_development' => $i % 2 === 0 ? 'Yes' : 'No',
                    'matrix_grade' => $this->matrix->calculate($scores, 2026),
                ]),
            );

            // A couple of development plans across models.
            foreach ($models->take(2) as $m => $model) {
                IndividualDevelopmentPlan::updateOrCreate(
                    [
                        'employee_id' => $eid,
                        'development_model_id' => $model->id,
                        'competency_name' => $competencies[($i + $m) % count($competencies)],
                    ],
                    [
                        'competency_type' => $m === 0 ? 'Soft Competency' : 'Technical Competency',
                        'development_program' => $programs[($i + $m) % count($programs)],
                        'review_tools' => 'Manager Review',
                        'expected_outcome' => 'Demonstrate improvement over the review period.',
                        'time_frame_start' => '2026-01-01',
                        'time_frame_end' => '2026-12-31',
                    ],
                );
            }

            // Succession summary for roughly half.
            if ($i % 2 === 0) {
                ResultSummary::updateOrCreate(
                    ['employee_id' => $eid],
                    [
                        'critical_position' => $i % 4 === 0 ? 'Yes' : 'No',
                        'successor_type' => ['Ready Now', 'Ready 1-2 Years', 'Ready 3-5 Years'][$i % 3],
                        'successor_to_position' => 'Senior Manager',
                    ],
                );
            }

            // 9-box: previous + current year.
            foreach ([2025, 2026] as $y => $year) {
                PerformanceAppraisal::updateOrCreate(
                    ['employee_id' => $eid, 'appraisal_year' => $year],
                    [
                        'grade' => ['A', 'B', 'C'][($i + $y) % 3],
                        'potential' => ['High', 'Medium', 'Low'][($i + $y) % 3],
                        'talent_box' => self::TALENT_BOXES[($i + $y) % count(self::TALENT_BOXES)],
                    ],
                );
            }
        }
    }

    /**
     * A spread of nine competency scores (0-4) that varies per employee.
     *
     * @return array<string, int>
     */
    private function scoresFor(int $i): array
    {
        $fields = [
            'synergized_team_score', 'integrity_score', 'growth_score', 'adaptive_score',
            'passion_score', 'manage_planning_score', 'decision_making_score',
            'relationship_building_score', 'developing_others_score',
        ];

        $base = 2 + ($i % 3); // 2..4
        $scores = [];
        foreach ($fields as $n => $field) {
            $scores[$field] = max(0, min(4, $base - ($n % 2)));
        }

        return $scores;
    }
}
