<?php

namespace Database\Seeders;

use App\Models\MatrixGradeConfig;
use Illuminate\Database\Seeder;

/**
 * Minimum competency scores required for each matrix grade level, for the
 * current period. Column order of the score arrays:
 *   synergized_team, integrity, growth, adaptive, passion,
 *   manage_planning, decision_making, relationship_building, developing_others.
 */
class MatrixGradeConfigSeeder extends Seeder
{
    private const PERIOD = 2026;

    /** grade_level => [9 minimum scores] */
    private const GRADES = [
        '2A' => [1, 1, 1, 1, 1, 0, 0, 0, 0],
        '2B' => [1, 1, 1, 1, 1, 0, 0, 0, 0],
        '2C' => [1, 1, 1, 1, 1, 0, 0, 0, 0],
        '2D' => [1, 1, 1, 1, 1, 0, 0, 0, 0],
        '3A' => [1, 1, 1, 1, 1, 1, 1, 1, 1],
        '3B' => [1, 1, 1, 1, 1, 1, 1, 1, 1],
        '4A' => [2, 2, 2, 2, 2, 2, 2, 2, 2],
        '4B' => [2, 2, 2, 2, 2, 2, 2, 2, 2],
        '5A' => [3, 3, 3, 3, 3, 2, 2, 2, 2],
        '5B' => [3, 3, 3, 3, 3, 2, 2, 2, 2],
        '6A' => [3, 3, 3, 3, 3, 3, 3, 3, 3],
        '6B' => [3, 3, 3, 3, 3, 3, 3, 3, 3],
        '7A' => [3, 3, 3, 3, 3, 3, 3, 3, 3],
        '7B' => [3, 3, 3, 3, 3, 3, 3, 3, 3],
        '8A' => [4, 4, 4, 4, 4, 4, 4, 4, 4],
        '8B' => [4, 4, 4, 4, 4, 4, 4, 4, 4],
        '9A' => [4, 4, 4, 4, 4, 4, 4, 4, 4],
        '9B' => [4, 4, 4, 4, 4, 4, 4, 4, 4],
    ];

    private const COLUMNS = [
        'synergized_team_min', 'integrity_min', 'growth_min', 'adaptive_min',
        'passion_min', 'manage_planning_min', 'decision_making_min',
        'relationship_building_min', 'developing_others_min',
    ];

    public function run(): void
    {
        foreach (self::GRADES as $gradeLevel => $scores) {
            MatrixGradeConfig::updateOrCreate(
                ['period' => self::PERIOD, 'grade_level' => $gradeLevel],
                array_combine(self::COLUMNS, $scores),
            );
        }
    }
}
