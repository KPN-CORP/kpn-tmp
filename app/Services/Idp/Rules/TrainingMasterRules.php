<?php

namespace App\Services\Idp\Rules;

use App\Models\Competency;
use App\Models\Training;
use App\Services\CorporateScopeService;
use App\Services\Idp\ChecksProficiencyLevels;
use App\Support\NormalizesInput;
use Illuminate\Database\Eloquent\Model;

/**
 * What a master training may point at.
 *
 * A training applies from now on, so everything it names has to be usable from
 * now on too:
 *
 *  - the competency has to belong to the chosen competency type, and be active;
 *  - every proficiency level has to be a rung of that competency's own ladder,
 *    and be active;
 *  - every work location has to belong to one of the chosen business units.
 *
 * What the training already stores is exempt from the active checks: once a
 * master is switched off, editing an unrelated field — a description, a
 * location — must not become impossible.
 */
class TrainingMasterRules
{
    use ChecksProficiencyLevels;
    use NormalizesInput;

    public function __construct(private readonly CorporateScopeService $corporate) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function check(array $data, ?Model $master): void
    {
        $competencyId = $this->intOrNull($data['competency_id'] ?? null);
        $competency = $competencyId === null ? null : Competency::find($competencyId);

        if ($competency !== null) {
            $this->assertCompetencyUsable($competency, $data, $master);
        }

        $levelIds = $this->intList($data['proficiency_level_ids'] ?? []);

        if ($levelIds !== []) {
            $this->assertLevelsBelongToCompetency($levelIds, $competencyId);
            $this->assertLevelsActive(
                $levelIds,
                $master instanceof Training
                    ? $master->proficiencyLevels()->pluck('competency_proficiency_levels.id')->all()
                    : [],
            );
        }

        $this->assertWorkLocationInBusinessUnit($data, $master);
    }

    /**
     * The competency a training builds has to be filed under the chosen type,
     * and — unless the training already stores it — still be active.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertCompetencyUsable(Competency $competency, array $data, ?Model $master): void
    {
        if ((int) $competency->competency_type_id !== (int) $data['competency_type_id']) {
            $this->fail(
                'competency_id',
                'The selected competency does not belong to the chosen competency type.'
            );
        }

        $unchanged = (int) $master?->competency_id === $competency->id;

        if (! $unchanged && ! $competency->is_active) {
            $this->fail(
                'competency_id',
                "Cannot use '{$competency->name_en}': it is inactive."
            );
        }
    }

    /**
     * Every work location has to be a corporate location of one of the chosen
     * business units — the union across them, since a training offered in
     * several units may run at a site of any of them.
     *
     * The check is skipped when none of those units have known locations — that
     * is either units the `locations` table doesn't cover or an unreachable
     * kpncorp, and neither should block a save. Locations the training already
     * stores are left alone, so a site that has since been renamed corporately
     * doesn't make the row uneditable.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertWorkLocationInBusinessUnit(array $data, ?Model $master): void
    {
        $locations = $this->stringList($data['work_locations'] ?? []);
        $businessUnits = $this->stringList($data['business_units'] ?? []);

        if ($locations === [] || $businessUnits === []) {
            return;
        }

        $stored = $master instanceof Training
            ? $master->workLocations()->pluck('work_location')->all()
            : [];

        $added = array_values(array_diff($locations, $stored));

        if ($added === []) {
            return;
        }

        $byBusinessUnit = $this->corporate->workLocations()['byBusinessUnit'];

        $known = collect($businessUnits)
            ->flatMap(fn (string $unit) => $byBusinessUnit[$unit] ?? [])
            ->unique()
            ->all();

        if ($known === []) {
            return;
        }

        $rejected = array_values(array_diff($added, $known));

        if ($rejected !== []) {
            $this->fail(
                'work_locations',
                'These are not work locations of the selected business units: '
                    .implode(', ', $rejected).'.'
            );
        }
    }
}
