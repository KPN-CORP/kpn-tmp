<?php

namespace App\Services;

use App\Models\CompetencyAssessment;
use App\Models\ResultSummary;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which of the facecard's restricted fields a user may see.
 *
 * Each field is revealed by its own `view_*` permission. The matching `input_*`
 * permission implies it, because the input drawer is seeded from the stored
 * value — someone who may edit a field has to be able to read it, or saving
 * would silently overwrite what they could not see.
 *
 * The fields are stripped on the SERVER (profile props, the single PDF and the
 * bulk zip), not merely hidden in the Vue, so a withheld value never leaves the
 * app. Everything else on the facecard is governed by the data-access
 * permissions (`ic_*` / `pm_*`) as before.
 */
final class FacecardVisibility
{
    /** field => the permissions that reveal it (any one is enough). */
    public const FIELDS = [
        'nine_box' => ['view_year_on_year', 'input_year_on_year'],
        'critical_position' => ['view_critical_position', 'input_successor_position'],
        'successor_type' => ['view_successor_type', 'input_successor_position'],
        'successor_position' => ['view_successor_position', 'input_successor_position'],
        'priority_dev' => ['view_priority_dev', 'input_competency_assessment'],
        'proposed_grade' => ['view_proposed_grade', 'input_competency_assessment'],
    ];

    /**
     * @return array<string, bool> field => visible
     */
    public static function for(?User $user): array
    {
        return collect(self::FIELDS)
            ->map(fn (array $permissions) => $user !== null && $user->canAny($permissions))
            ->all();
    }

    /**
     * Blank the 9-box mapping (potential + talent box) on each appraisal. The
     * corporate PA grade stays: it is not part of the talent mapping.
     *
     * @param  array<string, bool>  $visible
     */
    public static function appraisals(Collection $appraisals, array $visible): Collection
    {
        if ($visible['nine_box']) {
            return $appraisals;
        }

        return $appraisals->map(function ($a) {
            if (is_array($a)) {
                return array_merge($a, ['potential' => null, 'talent_box' => null]);
            }

            $a->potential = null;
            $a->talent_box = null;

            return $a;
        });
    }

    /**
     * @param  Collection<int, CompetencyAssessment>  $assessments
     * @param  array<string, bool>  $visible
     * @return Collection<int, CompetencyAssessment>
     */
    public static function assessments(Collection $assessments, array $visible): Collection
    {
        return $assessments->each(function (CompetencyAssessment $a) use ($visible) {
            if (! $visible['proposed_grade']) {
                $a->proposed_grade = null;
            }
            if (! $visible['priority_dev']) {
                $a->priority_for_development = null;
            }
        });
    }

    /**
     * @param  array<string, bool>  $visible
     */
    public static function resultSummary(?ResultSummary $summary, array $visible): ?ResultSummary
    {
        if (! $summary) {
            return null;
        }

        foreach ([
            'critical_position' => 'critical_position',
            'successor_type' => 'successor_type',
            'successor_position' => 'successor_to_position',
        ] as $field => $column) {
            if (! $visible[$field]) {
                $summary->{$column} = null;
            }
        }

        return $summary;
    }
}
