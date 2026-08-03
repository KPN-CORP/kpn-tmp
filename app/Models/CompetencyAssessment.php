<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetencyAssessment extends Model
{
    /** @use HasFactory<\Database\Factories\CompetencyAssessmentFactory> */
    use HasFactory;

    protected $table = 'competency_assessments';

    protected $fillable = [
        'employee_id',
        'assessment_date',
        'matrix_grade',
        'proposed_grade',
        'priority_for_development',
        'period',
        'synergized_team_score',
        'integrity_score',
        'growth_score',
        'adaptive_score',
        'passion_score',
        'manage_planning_score',
        'decision_making_score',
        'relationship_building_score',
        'developing_others_score',
    ];

    protected $casts = [
        'assessment_date' => 'date',
    ];

    /**
     * Human competency label => score-column prefix.
     */
    public static function getCompetencyMap(): array
    {
        return [
            'Synergized Team' => 'synergized_team',
            'Integrity for All Action' => 'integrity',
            'Growth for Co-Prosperity' => 'growth',
            'Adaptive to Change' => 'adaptive',
            'Passion for Excellence' => 'passion',
            'Manage and Planning' => 'manage_planning',
            'Decision Making' => 'decision_making',
            'Relationship Building' => 'relationship_building',
            'Developing Others' => 'developing_others',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
