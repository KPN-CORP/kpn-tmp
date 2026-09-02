<?php

namespace App\Services;

use App\Enums\MasterDataType;
use App\Models\Competency;
use App\Models\CompetencyImplementation;
use App\Models\CompetencyType;
use App\Models\DevelopmentProgram;
use App\Models\IndividualDevelopmentPlan;
use App\Models\KeyBehavior;
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
                [$master->proficiencyLevels(), 'it is assigned to a proficiency level'],
                [DevelopmentProgram::where('competency_type_id', $master->id), 'it is assigned to a development program'],
                [CompetencyImplementation::where('competency_type_id', $master->id), 'it is used in a master implementation'],
                [$master->trainings(), 'it is assigned to a master training'],
            ]),

            MasterDataType::ProficiencyLevel => $this->firstBlocker([
                [$master->competencies(), 'it is assigned to a competency'],
                [$master->keyBehaviors(), 'it still has key behaviors'],
                [DevelopmentProgram::where('proficiency_level_id', $master->id), 'it is assigned to a development program'],
                [$master->implementations(), 'it is used in a master implementation'],
                [$master->trainings(), 'it is assigned to a master training'],
            ]),

            MasterDataType::CompetencyName => $this->firstBlocker([
                [$master->implementations(), 'it is used in a master implementation'],
                [$master->trainings(), 'it is assigned to a master training'],
            ]),

            MasterDataType::Training => $this->firstBlocker([
                [$master->developmentPrograms(), 'it names a development program'],
            ]),

            MasterDataType::KeyBehavior, MasterDataType::DevelopmentProgram => null,

            MasterDataType::ReviewTools => null,
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
            MasterDataType::KeyBehavior => [
                // The level a behavior belongs to. Editing may move it.
                'proficiency_level_id' => $data['proficiency_level_id'],
            ],

            MasterDataType::CompetencyName, MasterDataType::ProficiencyLevel => [
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
            // An "Others" program free-types its competencies and level rather
            // than pointing at masters, so the two sets are mutually exclusive.
            'proficiency_level_id' => $isOthers ? null : ($data['proficiency_level_id'] ?? null),
            'custom_competency' => $isOthers ? ($data['custom_competency'] ?? null) : null,
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
            $levelIds = $this->intList($data['proficiency_level_ids'] ?? []);
            $master->proficiencyLevels()->sync($levelIds);

            // A key behavior only counts while one of the chosen levels owns it.
            $behaviorIds = $levelIds === []
                ? []
                : KeyBehavior::whereIn('id', $this->intList($data['key_behavior_ids'] ?? []))
                    ->whereIn('proficiency_level_id', $levelIds)
                    ->pluck('id')->all();
            $master->keyBehaviors()->sync($behaviorIds);

            // Program links are also editable from the program side, so only
            // touch them when this form actually sent them.
            if ($presentKeys === [] || in_array('related_programs', $presentKeys, true)) {
                $master->developmentPrograms()->sync($this->intList($data['related_programs'] ?? []));
            }

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
            $isOthers = $this->isOthersType($data['competency_type_id'] ?? null);

            $master->competencies()->sync(
                $isOthers ? [] : $this->intList($data['related_competencies'] ?? [])
            );

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
