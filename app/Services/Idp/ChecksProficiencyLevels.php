<?php

namespace App\Services\Idp;

use App\Models\CompetencyProficiencyLevel;
use App\Support\FailsValidation;

/**
 * The two checks every screen that *selects* proficiency levels has to make.
 *
 * There is no shared proficiency-level master any more: a level is a rung on
 * one competency's ladder. So both Master Training and Master Implementation —
 * each of which picks a competency and then some of its rungs — police the same
 * two things, and they differ on exemption for the same reason:
 *
 *  - ownership has NO exemption, because a level belongs to exactly one
 *    competency and one from elsewhere means the pick has to be redone;
 *  - the active flag exempts what the row already stores, because a level being
 *    switched off must never make an unrelated edit impossible.
 */
trait ChecksProficiencyLevels
{
    use FailsValidation;

    /**
     * Every submitted level has to be a rung of the given competency's own
     * ladder.
     *
     * @param  array<int, int>  $levelIds
     */
    protected function assertLevelsBelongToCompetency(array $levelIds, ?int $competencyId): void
    {
        if ($levelIds === [] || $competencyId === null) {
            return;
        }

        $foreign = CompetencyProficiencyLevel::whereIn('id', $levelIds)
            ->where('competency_id', '!=', $competencyId)
            ->orderBy('sequence')
            ->pluck('name_en');

        if ($foreign->isNotEmpty()) {
            $this->fail(
                'proficiency_level_ids',
                'These proficiency levels do not belong to the chosen competency: '
                    .$foreign->implode(', ').'.'
            );
        }
    }

    /**
     * Reject inactive proficiency levels, naming them. Ids in `$exempt` are
     * already stored on the row being edited and are left alone.
     *
     * @param  array<int, int>  $levelIds
     * @param  array<int, int>  $exempt
     */
    protected function assertLevelsActive(array $levelIds, array $exempt): void
    {
        $added = array_values(array_diff($levelIds, $exempt));

        if ($added === []) {
            return;
        }

        $rejected = CompetencyProficiencyLevel::whereIn('id', $added)
            ->inactive()
            ->orderBy('sequence')
            ->pluck('name_en');

        if ($rejected->isNotEmpty()) {
            $this->fail(
                'proficiency_level_ids',
                'These proficiency levels are inactive: '.$rejected->implode(', ').'.'
            );
        }
    }
}
