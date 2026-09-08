<?php

namespace App\Services;

use App\Enums\MasterDataType;
use App\Models\Competency;
use App\Models\CompetencyImplementation;
use App\Models\CompetencyProficiencyLevel;
use App\Models\CompetencyType;
use App\Models\DevelopmentProgram;
use App\Models\IndividualDevelopmentPlan;
use App\Models\Training;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes for the IDP master data behind the shared `/idp-setting/masters`
 * endpoints. One method per operation, dispatching on {@see MasterDataType}.
 *
 * The `$data` arrays it takes are the validated request payloads, still in the
 * wire shape the settings screens post (`value_en` / `value_id` / `*_ids`).
 * Translating that to the stored column names happens here, in one place.
 */
class IdpMasterService
{
    public function __construct(private readonly MasterStatusAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(MasterDataType $type, array $data): Model
    {
        return DB::transaction(function () use ($type, $data) {
            /** @var Model $master */
            $master = $type->modelClass()::create($this->attributes($type, $data));

            $this->syncLinks($type, $master, $data);

            // A master created switched off is a transition worth recording;
            // one created active is just the default and needs no entry.
            if ($type->hasActiveState() && ! $master->is_active) {
                $this->audit->record($type->value, $master->id, $master->name_en, false, Auth::user());
            }

            return $master;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $presentKeys  request keys the form actually sent,
     *                                           so an edit never wipes links it did not manage
     */
    public function update(MasterDataType $type, Model $master, array $data, array $presentKeys = []): Model
    {
        return DB::transaction(function () use ($type, $master, $data, $presentKeys) {
            $previousName = $master->name_en;
            $wasActive = (bool) $master->is_active;

            $master->fill($this->attributes($type, $data))->save();

            $this->syncLinks($type, $master, $data, $presentKeys);

            $this->cascadeRename($type, $previousName, $master->name_en);

            // Only the transition is logged, so re-saving an unchanged form
            // never adds an entry.
            if ($type->hasActiveState() && $wasActive !== (bool) $master->is_active) {
                $this->audit->record(
                    $type->value,
                    $master->id,
                    $master->name_en,
                    (bool) $master->is_active,
                    Auth::user(),
                );
            }

            return $master;
        });
    }

    /**
     * Flip a master's active flag from the list screen, recording who did it.
     * A no-op when the flag is already in the requested state, so a double
     * click never writes a second audit entry.
     */
    public function setActive(MasterDataType $type, Model $master, bool $active): void
    {
        if ((bool) $master->is_active === $active) {
            return;
        }

        $master->forceFill(['is_active' => $active])->save();

        $this->audit->record($type->value, $master->id, $master->name_en, $active, Auth::user());
    }

    /**
     * This master's activate / deactivate history, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function statusHistory(MasterDataType $type, int $id): array
    {
        return $this->audit->for($type->value, $id);
    }

    /**
     * Why this row cannot be deleted, or null when it can.
     */
    public function deletionBlocker(MasterDataType $type, Model $master): ?string
    {
        $name = $master->name_en;

        $blocked = match ($type) {
            MasterDataType::CompetencyType => $this->firstBlocker([
                [Competency::where('competency_type_id', $master->id), 'it is assigned to a competency'],
                [DevelopmentProgram::where('competency_type_id', $master->id), 'it is assigned to a development program'],
                [CompetencyImplementation::where('competency_type_id', $master->id), 'it is used in a master implementation'],
                [$master->trainings(), 'it is assigned to a master training'],
            ]),

            // A competency owns the proficiency levels other screens select,
            // so deleting one would take those rungs (and everything pointing
            // at them) with it.
            MasterDataType::CompetencyName => $this->firstBlocker([
                [$master->implementations(), 'it is used in a master implementation'],
                [$master->trainings(), 'it is assigned to a master training'],
                [$master->developmentPrograms(), 'it is developed by a development program'],
            ]),

            MasterDataType::Training => $this->firstBlocker([
                [$master->developmentPrograms(), 'it names a development program'],
            ]),

            MasterDataType::DevelopmentProgram, MasterDataType::ReviewTools => null,
        };

        if ($blocked !== null) {
            return "Cannot delete '{$name}': {$blocked}.";
        }

        // Anything an IDP row names verbatim stays undeletable while in use.
        $column = $type->idpColumn();
        if ($column !== null && IndividualDevelopmentPlan::where($column, $name)->exists()) {
            return "Cannot delete '{$name}': it is used in an IDP.";
        }

        return null;
    }

    public function delete(MasterDataType $type, Model $master): void
    {
        // Link rows cascade in the database; only the row itself is removed here.
        $master->delete();
    }

    /**
     * The stored column values for a master row, per kind.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(MasterDataType $type, array $data): array
    {
        $attributes = [
            'name_en' => $data['value_en'],
            'name_id' => $this->nullIfBlank($data['value_id'] ?? null),
        ];

        if ($type->hasDescription()) {
            $attributes['description_en'] = $data['description_en'] ?? null;
            $attributes['description_id'] = $data['description_id'] ?? null;
        }

        if ($type->hasActiveState()) {
            // Absent means active: a form that never shows the switch (and any
            // older caller) keeps creating usable masters.
            $attributes['is_active'] = (bool) ($data['is_active'] ?? true);
        }

        return $attributes + match ($type) {
            MasterDataType::CompetencyName => [
                'competency_type_id' => $data['competency_type_id'] ?? null,
            ],

            MasterDataType::DevelopmentProgram => $this->programAttributes($data),

            MasterDataType::Training => $this->trainingAttributes($data),

            default => [],
        };
    }

    /**
     * What a master training is scoped to: the competency it builds, through
     * its type. Everything else it carries is a list — the proficiency levels
     * it targets, and the business units / work locations it is offered in —
     * and those are synced in {@see syncLinks()}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function trainingAttributes(array $data): array
    {
        return [
            'competency_type_id' => $data['competency_type_id'] ?? null,
            'competency_id' => $data['competency_id'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function programAttributes(array $data): array
    {
        $typeId = $data['competency_type_id'] ?? null;
        $isOthers = $this->isOthersType($typeId);

        return [
            'development_model_id' => $data['development_model_id'] ?? null,
            'competency_type_id' => $typeId,
            // Where the name came from: a training, or null when it was typed.
            // The name itself is already in `value_en` / `value_id`, copied off
            // the training during validation.
            'training_id' => $data['training_id'] ?? null,
            // An "Others" program free-types its proficiency level rather than
            // taking it from an implementation, so the two are mutually
            // exclusive. Its competency is a real master either way.
            'proficiency_level_id' => $isOthers ? null : ($data['proficiency_level_id'] ?? null),
            'custom_proficiency_level' => $isOthers ? ($data['custom_proficiency_level'] ?? null) : null,
        ];
    }

    /**
     * Bring the many-to-many links in step with the submitted selection.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $presentKeys
     */
    private function syncLinks(MasterDataType $type, Model $master, array $data, array $presentKeys = []): void
    {
        if ($type === MasterDataType::CompetencyName) {
            /** @var Competency $master */

            // Program links are also editable from the program side, so only
            // touch them when this form actually sent them.
            if ($presentKeys === [] || in_array('related_programs', $presentKeys, true)) {
                $master->developmentPrograms()->sync($this->intList($data['related_programs'] ?? []));
            }

            // Sub-competencies belong to this form alone, but the same guard
            // applies: a caller that did not send them must not wipe them.
            if ($presentKeys === [] || in_array('sub_competencies', $presentKeys, true)) {
                $this->syncSubCompetencies($master, $data['sub_competencies'] ?? []);
            }

            if ($presentKeys === [] || in_array('proficiency_levels', $presentKeys, true)) {
                $this->syncOwnedProficiencyLevels($master, $data['proficiency_levels'] ?? []);
            }

            return;
        }

        if ($type === MasterDataType::CompetencyType) {
            /** @var CompetencyType $master */
            $this->replaceValues($master->businessUnits(), 'business_unit', $data['business_units'] ?? []);

            return;
        }

        if ($type === MasterDataType::Training) {
            /** @var Training $master */
            $master->proficiencyLevels()->sync($this->intList($data['proficiency_level_ids'] ?? []));

            // The corporate scope is raw strings, so each list is replaced
            // wholesale — the same way a program's grades are.
            $this->replaceValues($master->businessUnits(), 'business_unit', $data['business_units'] ?? []);
            $this->replaceValues($master->workLocations(), 'work_location', $data['work_locations'] ?? []);

            return;
        }

        if ($type === MasterDataType::DevelopmentProgram) {
            /** @var DevelopmentProgram $master */
            $master->competencies()->sync($this->intList($data['related_competencies'] ?? []));

            $master->grades()->delete();
            $grades = collect($data['grades'] ?? [])
                ->map(fn ($grade) => trim((string) $grade))
                ->filter()
                ->unique()
                ->values();

            if ($grades->isNotEmpty()) {
                $master->grades()->createMany($grades->map(fn ($grade) => ['grade' => $grade])->all());
            }
        }
    }

    /**
     * Bring a competency's own proficiency ladder in step with the submitted
     * rows, and each rung's key behaviors with it.
     *
     * Same id-preserving contract as {@see syncSubCompetencies()}: a row that
     * comes back with its own id is updated in place (so renumbering or
     * renaming a rung keeps its identity, and its activation history keeps
     * pointing at the same row), rows with no id — or an id belonging to
     * another competency — are created, and rows the form no longer carries are
     * deleted, taking their behaviors with them. A rung with a blank name is
     * dropped rather than saved.
     *
     * The rung's `sequence` is NOT taken from the request: the ladder is ordered
     * by row position on the form (moved with up/down buttons), so the position
     * in the submitted list is the order. It is counted over the rows actually
     * kept, since the submitted keys are deliberately not re-indexed after the
     * blank ones are dropped — using the array key would leave gaps.
     *
     * Switching a rung on or off is recorded in the same audit log the masters
     * use, so the per-row history reads back the way theirs does.
     */
    private function syncOwnedProficiencyLevels(Competency $competency, mixed $rows): void
    {
        $existing = $competency->proficiencyLevels()->get()->keyBy('id');
        $keep = [];
        $sequence = 0;

        foreach ((array) $rows as $row) {
            $row = (array) $row;

            $name = trim((string) ($row['name_en'] ?? ''));

            if ($name === '') {
                continue;
            }

            $attributes = [
                'name_en' => $name,
                'name_id' => $this->nullIfBlank($row['name_id'] ?? null),
                'description_en' => $this->nullIfBlank($row['description_en'] ?? null),
                'description_id' => $this->nullIfBlank($row['description_id'] ?? null),
                'sequence' => ++$sequence,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ];

            $level = $existing->get((int) ($row['id'] ?? 0));

            if ($level !== null) {
                $wasActive = (bool) $level->is_active;
                $level->update($attributes);

                // Only the transition is logged, so re-saving an unchanged
                // ladder adds nothing.
                if ($wasActive !== (bool) $level->is_active) {
                    $this->audit->record(
                        MasterStatusAudit::COMPETENCY_LEVEL,
                        $level->id,
                        $level->name_en,
                        (bool) $level->is_active,
                        Auth::user(),
                    );
                }
            } else {
                $level = $competency->proficiencyLevels()->create($attributes);

                // A rung created switched off is a transition worth recording;
                // one created active is just the default.
                if (! $level->is_active) {
                    $this->audit->record(
                        MasterStatusAudit::COMPETENCY_LEVEL,
                        $level->id,
                        $level->name_en,
                        false,
                        Auth::user(),
                    );
                }
            }

            $keep[] = $level->id;
            $this->syncOwnedKeyBehaviors($level, $row['key_behaviors'] ?? []);
        }

        $competency->proficiencyLevels()
            ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();
    }

    /**
     * The key behaviors of one rung, same id-preserving contract as the rungs
     * themselves — and, like them, ordered by their position in the submitted
     * list rather than by a number the form sends.
     */
    private function syncOwnedKeyBehaviors(CompetencyProficiencyLevel $level, mixed $rows): void
    {
        $existing = $level->keyBehaviors()->get()->keyBy('id');
        $keep = [];
        $sequence = 0;

        foreach ((array) $rows as $row) {
            $row = (array) $row;

            $attributes = [
                'name_en' => trim((string) ($row['name_en'] ?? '')),
                'name_id' => $this->nullIfBlank($row['name_id'] ?? null),
                'sequence' => $sequence + 1,
            ];

            if ($attributes['name_en'] === '') {
                continue;
            }

            $sequence++;

            $behavior = $existing->get((int) ($row['id'] ?? 0));

            if ($behavior !== null) {
                $behavior->update($attributes);
                $keep[] = $behavior->id;

                continue;
            }

            $keep[] = $level->keyBehaviors()->create($attributes)->id;
        }

        $level->keyBehaviors()
            ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();
    }

    /**
     * Bring a competency's sub-competencies in step with the submitted rows.
     *
     * Unlike the other child lists here this is not a wholesale replace: a row
     * that came back with its own id is UPDATED in place, so ids stay stable
     * across an edit (renaming one part of a competency does not silently
     * replace it with a new row). Rows with no id — or an id belonging to
     * another competency — are created; rows the form no longer carries are
     * deleted. A row with a blank name is dropped: the form's own remove button
     * is how a row goes away, but an unfilled one should not become a record.
     */
    private function syncSubCompetencies(Competency $competency, mixed $rows): void
    {
        $existing = $competency->subCompetencies()->get()->keyBy('id');
        $keep = [];

        foreach ((array) $rows as $row) {
            $row = (array) $row;

            $attributes = [
                'name_en' => trim((string) ($row['name_en'] ?? '')),
                'name_id' => $this->nullIfBlank($row['name_id'] ?? null),
                'description_en' => $this->nullIfBlank($row['description_en'] ?? null),
                'description_id' => $this->nullIfBlank($row['description_id'] ?? null),
            ];

            if ($attributes['name_en'] === '') {
                continue;
            }

            $current = $existing->get((int) ($row['id'] ?? 0));

            if ($current !== null) {
                $current->update($attributes);
                $keep[] = $current->id;

                continue;
            }

            $keep[] = $competency->subCompetencies()->create($attributes)->id;
        }

        $competency->subCompetencies()
            ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();
    }

    /**
     * Replace a child list of raw corporate strings with the submitted one,
     * trimmed and de-duplicated.
     */
    private function replaceValues(HasMany $relation, string $column, mixed $values): void
    {
        $relation->delete();

        $clean = collect((array) $values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($clean->isNotEmpty()) {
            $relation->createMany($clean->map(fn (string $value) => [$column => $value])->all());
        }
    }

    /**
     * IDP rows store a master's name verbatim, so a rename has to follow.
     */
    private function cascadeRename(MasterDataType $type, ?string $from, ?string $to): void
    {
        $column = $type->idpColumn();

        if ($column === null || $from === null || $from === $to) {
            return;
        }

        IndividualDevelopmentPlan::where($column, $from)->update([$column => $to]);
    }

    /**
     * The first blocker whose query matches, or null when none do.
     *
     * @param  array<int, array{0: Builder|Relation, 1: string}>  $checks
     */
    private function firstBlocker(array $checks): ?string
    {
        foreach ($checks as [$query, $reason]) {
            if ($query->exists()) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Whether the given competency type is the catch-all "Others".
     */
    private function isOthersType(mixed $id): bool
    {
        if ($id === null || $id === '') {
            return false;
        }

        return CompetencyType::find($id)?->isOthers() ?? false;
    }

    /**
     * @return array<int, int>
     */
    private function intList(mixed $values): array
    {
        return array_values(array_unique(array_map('intval', (array) $values)));
    }

    private function nullIfBlank(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }
}
