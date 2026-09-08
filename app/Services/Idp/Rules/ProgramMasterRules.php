<?php

namespace App\Services\Idp\Rules;

use App\Models\Competency;
use App\Models\CompetencyImplementation;
use App\Models\DevelopmentModel;
use App\Models\DevelopmentProgram;
use App\Models\Training;
use App\Services\Idp\MasterOptionService;
use App\Support\FailsValidation;
use App\Support\NormalizesInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What a development program may point at.
 *
 * A program is scoped through the master implementations of the competency it
 * develops: the proficiency level it targets has to be one an implementation
 * maps that competency to, and its grades have to fall inside that mapping's
 * coverage. An inactive competency can no longer be developed at all.
 *
 * The name and description are the development model's business rather than
 * the program's: a model flagged `uses_master_training` names its programs from
 * the Master Training catalogue, so one is required and both values are copied
 * off it; any other model types them by hand.
 *
 * This applies to every competency type, the catch-all "Others" included — a
 * program on it picks a real competency master too. What "Others" still
 * free-types is its proficiency level, which arrives in
 * `custom_proficiency_level` and so leaves nothing here to police.
 *
 * Only what the form *adds* is checked. Whatever the program already stores
 * stays valid, so editing an unrelated field never fails because a competency
 * has since been deactivated or an implementation has since narrowed — the same
 * exemption the competency form gives its pinned levels.
 */
class ProgramMasterRules
{
    use FailsValidation;
    use NormalizesInput;

    public function __construct(private readonly MasterOptionService $options) {}

    /**
     * Whether this program has to name itself from the Master Training
     * catalogue — which the development model it is filed under decides, not
     * the program. A model with no such declaration (or no model at all) means
     * the name and description are typed by hand.
     */
    public function requiresTraining(Request $request): bool
    {
        $model = DevelopmentModel::find(
            $this->intOrNull($request->input('development_model_id'))
        );

        return (bool) $model?->uses_master_training;
    }

    /**
     * Copy the chosen training's name and description onto the request, so a
     * training-sourced program is validated and stored exactly like a typed
     * one. The program keeps its own `name_en` / `name_id` and descriptions:
     * IDP rows name a program verbatim and every list reads those columns, so
     * `training_id` records where they came from rather than replacing them.
     *
     * The copy happens on every save, so re-saving a program picks up a
     * training that has since been renamed.
     */
    public function prepare(Request $request): void
    {
        if (! $this->requiresTraining($request)) {
            // This model's programs are written out by hand; nothing is taken
            // from the catalogue, whatever the request carried.
            $request->merge(['training_id' => null]);

            return;
        }

        $training = Training::find($this->intOrNull($request->input('training_id')));

        if ($training === null) {
            // Nothing chosen, or it has since gone. The name rules are relaxed
            // for this case so the missing training is the one error reported.
            $request->merge(['training_id' => null]);

            return;
        }

        $request->merge([
            'training_id' => $training->id,
            'value_en' => $training->name_en,
            'value_id' => $training->name_id,
            'description_en' => $training->description_en,
            'description_id' => $training->description_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function check(array $data, ?Model $master): void
    {
        $program = $master instanceof DevelopmentProgram ? $master : null;

        $competencyIds = $this->intList($data['related_competencies'] ?? []);

        $this->assertCompetenciesActive(
            $competencyIds,
            $program?->competencies()->pluck('competencies.id')->all() ?? [],
        );

        $levelId = $this->intOrNull($data['proficiency_level_id'] ?? null);

        if ($levelId === null) {
            return;
        }

        $scopes = $competencyIds === []
            ? collect()
            : $this->options->implementationScopes(
                CompetencyImplementation::whereIn('competency_id', $competencyIds)
            );

        $covers = fn (array $scope) => in_array($levelId, $scope['proficiency_level_ids'], true);

        if ($levelId !== $program?->proficiency_level_id && ! $scopes->contains($covers)) {
            $this->fail(
                'proficiency_level_id',
                'The selected proficiency level is not implemented for the chosen competency.'
            );
        }

        $this->assertGradesImplemented(
            $data['grades'] ?? [],
            $program?->grades()->pluck('grade')->all() ?? [],
            $scopes->filter($covers),
        );
    }

    /**
     * Reject competencies that have been switched off — nothing new can develop
     * them. Ids in `$exempt` are already on the program.
     *
     * A program develops a single competency, but the check stays list-shaped:
     * the link behind it is a pivot, and one day it may carry more again.
     *
     * @param  array<int, int>  $competencyIds
     * @param  array<int, int>  $exempt
     */
    private function assertCompetenciesActive(array $competencyIds, array $exempt): void
    {
        $added = array_values(array_diff($competencyIds, $exempt));

        if ($added === []) {
            return;
        }

        $inactive = Competency::whereIn('id', $added)
            ->inactive()
            ->orderBy('name_en')
            ->pluck('name_en');

        if ($inactive->isNotEmpty()) {
            $this->fail(
                'related_competencies',
                ($inactive->count() === 1 ? 'This competency is' : 'These competencies are')
                    .' inactive: '.$inactive->implode(', ').'.'
            );
        }
    }

    /**
     * Reject grades the implementation map does not cover for the chosen level.
     * With no mapping at all, or one that lists no grades of its own (meaning
     * every grade), there is nothing to police. Grades the program already
     * stores are exempt.
     *
     * @param  array<int, string>  $grades
     * @param  array<int, string>  $exempt
     * @param  Collection<int, array<string, mixed>>  $scopes  mappings covering the level
     */
    private function assertGradesImplemented(array $grades, array $exempt, Collection $scopes): void
    {
        if ($scopes->isEmpty() || $scopes->contains(fn (array $s) => $s['grades'] === [])) {
            return;
        }

        $covered = $scopes->flatMap(fn (array $s) => $s['grades'])->unique()->all();

        $rejected = array_values(array_diff(
            array_map('strval', $grades),
            $exempt,
            $covered,
        ));

        if ($rejected !== []) {
            $this->fail(
                'grades',
                'These grades are not covered by the master implementation of the selected proficiency level: '
                    .implode(', ', $rejected).'.'
            );
        }
    }
}
