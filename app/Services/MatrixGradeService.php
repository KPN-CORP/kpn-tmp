<?php

namespace App\Services;

use App\Models\MatrixGradeConfig;

/**
 * Derives an employee's matrix (target) grade from their nine competency scores.
 * For the given period, the grade is the highest configured grade_level whose
 * per-competency minimums are all satisfied by the scores.
 */
class MatrixGradeService
{
    /** score field => matching config minimum field */
    private const MAP = [
        'synergized_team_score' => 'synergized_team_min',
        'integrity_score' => 'integrity_min',
        'growth_score' => 'growth_min',
        'adaptive_score' => 'adaptive_min',
        'passion_score' => 'passion_min',
        'manage_planning_score' => 'manage_planning_min',
        'decision_making_score' => 'decision_making_min',
        'relationship_building_score' => 'relationship_building_min',
        'developing_others_score' => 'developing_others_min',
    ];

    /**
     * @param  array<string, int|null>  $scores
     */
    public function calculate(array $scores, int|string $period): ?string
    {
        $configs = MatrixGradeConfig::where('period', $period)
            ->orderBy('grade_level')
            ->get();

        $best = null;

        foreach ($configs as $config) {
            if ($this->satisfies($scores, $config)) {
                // grade_level strings sort naturally (2A < 3B < 9B); keep the highest.
                if ($best === null || strcmp($config->grade_level, $best) > 0) {
                    $best = $config->grade_level;
                }
            }
        }

        return $best;
    }

    private function satisfies(array $scores, MatrixGradeConfig $config): bool
    {
        foreach (self::MAP as $scoreField => $minField) {
            if ((int) ($scores[$scoreField] ?? 0) < (int) $config->{$minField}) {
                return false;
            }
        }

        return true;
    }
}
