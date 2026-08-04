<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompetencyAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('input_competency_assessment') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $score = ['nullable', 'integer', 'min:0', 'max:4'];

        return [
            'employee_id' => ['required', 'string'],
            'assessment_date' => ['required', 'date', 'before_or_equal:today'],
            'proposed_grade' => ['nullable', 'string', 'max:10'],
            'priority_for_development' => ['required', 'string', 'in:Yes,No'],
            'synergized_team_score' => $score,
            'integrity_score' => $score,
            'growth_score' => $score,
            'adaptive_score' => $score,
            'passion_score' => $score,
            'manage_planning_score' => $score,
            'decision_making_score' => $score,
            'relationship_building_score' => $score,
            'developing_others_score' => $score,
        ];
    }

    /**
     * The nine score fields, for the controller to pass to MatrixGradeService.
     *
     * @return array<string, int|null>
     */
    public function scores(): array
    {
        return $this->only([
            'synergized_team_score', 'integrity_score', 'growth_score',
            'adaptive_score', 'passion_score', 'manage_planning_score',
            'decision_making_score', 'relationship_building_score', 'developing_others_score',
        ]);
    }
}
