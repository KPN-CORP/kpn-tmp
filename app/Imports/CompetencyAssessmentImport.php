<?php

namespace App\Imports;

use App\Models\CompetencyAssessment;
use App\Services\MatrixGradeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports competency assessments. Each row is keyed by header
 * (employee_id, assessment_date, <competency>_score…, proposed_grade,
 * priority_for_development). The matrix grade is derived, not imported.
 */
class CompetencyAssessmentImport implements ToCollection, WithHeadingRow
{
    private const SCORE_FIELDS = [
        'synergized_team_score', 'integrity_score', 'growth_score', 'adaptive_score',
        'passion_score', 'manage_planning_score', 'decision_making_score',
        'relationship_building_score', 'developing_others_score',
    ];

    private int $imported = 0;

    /** @var array<int, string> */
    private array $errors = [];

    public function __construct(private readonly MatrixGradeService $matrix)
    {
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $line = $index + 2; // account for the heading row

            $employeeId = trim((string) $row->get('employee_id'));
            $rawDate = $row->get('assessment_date');

            if ($employeeId === '' || ! $rawDate) {
                $this->errors[] = "Row {$line}: missing employee_id or assessment_date.";
                continue;
            }

            try {
                $date = Carbon::parse($rawDate);
            } catch (\Throwable) {
                $this->errors[] = "Row {$line}: invalid assessment_date.";
                continue;
            }

            $scores = [];
            foreach (self::SCORE_FIELDS as $field) {
                $value = $row->get($field);
                $scores[$field] = $value === null || $value === '' ? null : (int) $value;
            }

            $period = $date->year;

            CompetencyAssessment::updateOrCreate(
                ['employee_id' => $employeeId, 'period' => $period],
                array_merge($scores, [
                    'assessment_date' => $date,
                    'proposed_grade' => $row->get('proposed_grade') ?: null,
                    'priority_for_development' => $row->get('priority_for_development') ?: null,
                    'matrix_grade' => $this->matrix->calculate($scores, $period),
                ]),
            );

            $this->imported++;
        }
    }

    public function imported(): int
    {
        return $this->imported;
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
