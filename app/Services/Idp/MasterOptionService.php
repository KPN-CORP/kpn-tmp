<?php

namespace App\Services\Idp;

use App\Models\Competency;
use App\Models\CompetencyImplementation;
use App\Models\CompetencyKeyBehavior;
use App\Models\CompetencyProficiencyLevel;
use App\Models\CompetencyType;
use App\Models\DevelopmentProgram;
use App\Models\SubCompetency;
use App\Models\Training;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The Inertia payloads the IDP master screens share.
 *
 * Six screens select from the same masters, and each used to shape those lists
 * itself. Every shape a screen sends over the wire is built here instead, so
 * two screens reading the same master can no longer disagree about its fields —
 * which matters because the front end narrows the same lists against each other
 * (a training's levels come from its competency, a program's grades from an
 * implementation of its competency, and so on).
 */
class MasterOptionService
{
    /**
     * Shape a master row into the option payload the settings screens expect.
     *
     * `value` is the canonical name and `value_en`/`value_id` are the localized
     * display names. The old single table stored those separately and let them
     * drift; `name_en` is now the single source for both `value` and
     * `value_en`.
     *
     * @return array<string, mixed>
     */
    public function option(Model $row): array
    {
        $option = [
            'id' => $row->id,
            'value' => $row->name_en,
            'value_en' => $row->name_en,
            'value_id' => $row->name_id,
        ];

        // Masters that can be switched off carry the flag on every payload, so
        // each screen can badge the row and keep inactive ones out of its
        // pickers.
        if (array_key_exists('is_active', $row->getAttributes())) {
            $option['is_active'] = (bool) $row->is_active;
        }

        return $option;
    }

    /**
     * @param  Collection<int, Model>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function options(Collection $rows): Collection
    {
        return $rows->map(fn (Model $row) => $this->option($row));
    }

    /**
     * Competency types, each carrying how many competencies reference it so the
     * UI can guard deletes.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function competencyTypes(): Collection
    {
        return CompetencyType::withCount('competencies')
            ->with('businessUnits')
            ->orderBy('name_en')
            ->get()
            ->map(fn (CompetencyType $ct) => $this->option($ct) + [
                'description_en' => $ct->description_en,
                'description_id' => $ct->description_id,
                'competencies_count' => (int) $ct->competencies_count,
                'business_units' => $ct->businessUnits->pluck('business_unit')->all(),
            ]);
    }

    /**
     * Every proficiency level in the app, each carrying the competency that
     * owns it.
     *
     * There is no shared proficiency-level master any more: a level is a rung
     * on one competency's ladder. The screens that select levels — Master
     * Implementation, Master Training and the development-program form — all
     * pick a competency first and narrow this list by `competency_id`; they
     * also need the whole list to name a level a row already stores.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function proficiencyLevels(): Collection
    {
        return CompetencyProficiencyLevel::orderBy('competency_id')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (CompetencyProficiencyLevel $level) => $this->option($level) + [
                'competency_id' => $level->competency_id,
                'sequence' => $level->sequence,
            ]);
    }

    /**
     * The training catalogue as an option list — what a development program
     * filed under a master-training model takes its name and description from.
     *
     * The descriptions ride along so the form can read back exactly what the
     * program will store; the server copies them again on save, which is what
     * the stored values actually rely on.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function trainings(): Collection
    {
        return Training::orderBy('name_en')->get()
            ->map(fn (Training $training) => $this->option($training) + [
                'description_en' => $training->description_en,
                'description_id' => $training->description_id,
            ]);
    }

    /**
     * Competencies as options, each carrying the type that classifies it — the
     * cascade every selecting screen runs (type -> competency).
     *
     * `$with` adds the extra fields one screen needs on top.
     *
     * @param  (callable(Competency): array<string, mixed>)|null  $with
     * @param  array<int, string>  $load  relations to eager-load
     * @return Collection<int, array<string, mixed>>
     */
    public function competencies(?callable $with = null, array $load = []): Collection
    {
        return Competency::with($load)
            ->orderBy('name_en')
            ->get()
            ->map(fn (Competency $c) => $this->option($c) + [
                'competency_type_id' => $c->competency_type_id,
            ] + ($with === null ? [] : $with($c)));
    }

    /**
     * One competency in full, as both its list screen and its form read it.
     *
     * @return array<string, mixed>
     */
    public function competencyPayload(Competency $competency): array
    {
        return $this->option($competency) + [
            'description_en' => $competency->description_en,
            'description_id' => $competency->description_id,
            'competency_type_id' => $competency->competency_type_id,
            // The competency's own proficiency ladder, each rung with the key
            // behaviors observed at it. Field names are the DB's own, like the
            // sub-competencies: nested rows, no legacy wire contract to keep.
            'proficiency_levels' => $competency->proficiencyLevels->map(
                fn (CompetencyProficiencyLevel $level) => [
                    'id' => $level->id,
                    'name_en' => $level->name_en,
                    'name_id' => $level->name_id,
                    'description_en' => $level->description_en,
                    'description_id' => $level->description_id,
                    // Both sequences are the row's position on the form, so
                    // they only ever arrive here; the form does not post them.
                    'sequence' => $level->sequence,
                    'is_active' => (bool) $level->is_active,
                    'key_behaviors' => $level->keyBehaviors->map(
                        fn (CompetencyKeyBehavior $behavior) => [
                            'id' => $behavior->id,
                            'name_en' => $behavior->name_en,
                            'name_id' => $behavior->name_id,
                            'sequence' => $behavior->sequence,
                        ]
                    )->all(),
                ]
            )->all(),
            // Nested rows, in the DB's own field names: there is no legacy
            // single-table wire contract to keep here, unlike the masters'
            // value_en / value_id.
            'sub_competencies' => $competency->subCompetencies->map(fn (SubCompetency $sc) => [
                'id' => $sc->id,
                'name_en' => $sc->name_en,
                'name_id' => $sc->name_id,
                'description_en' => $sc->description_en,
                'description_id' => $sc->description_id,
            ])->all(),
        ];
    }

    /**
     * Development programs as the settings screen reads them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function developmentPrograms(): Collection
    {
        return DevelopmentProgram::with(['developmentModel:id,name,name_en,name_id,percentage', 'grades'])
            ->orderBy('name_en')
            ->get()
            ->map(fn (DevelopmentProgram $p) => $this->option($p) + [
                'development_model_id' => $p->development_model_id,
                'model_name' => $p->developmentModel?->name,
                'competency_type_id' => $p->competency_type_id,
                'description_en' => $p->description_en,
                'description_id' => $p->description_id,
                // Null when the name was typed rather than taken from a training.
                'training_id' => $p->training_id,
                'proficiency_level_id' => $p->proficiency_level_id,
                // Free-typed proficiency level (an "Others" program).
                'custom_proficiency_level' => $p->custom_proficiency_level,
                'grades' => $p->grades->pluck('grade')->values(),
            ]);
    }

    /**
     * The master-implementation map, flattened to what the development-program
     * form needs: which proficiency levels a competency is implemented at, and
     * which grades each of those mappings covers.
     *
     * An empty `grades` list means the mapping covers every grade, exactly as
     * it does on the implementation screen itself.
     *
     * @param  Builder|null  $query  narrow the rows (defaults to all of them)
     * @return Collection<int, array<string, mixed>>
     */
    public function implementationScopes(?Builder $query = null): Collection
    {
        return ($query ?? CompetencyImplementation::query())
            ->with(['proficiencyLevels:id', 'grades'])
            ->get()
            ->map(fn (CompetencyImplementation $i) => [
                'competency_id' => $i->competency_id,
                'proficiency_level_ids' => $i->proficiencyLevels->pluck('id')->all(),
                'grades' => $i->grades->pluck('grade')->values()->all(),
            ])
            ->values();
    }
}
