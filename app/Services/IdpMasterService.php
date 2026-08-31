<?php

namespace App\Services;

use App\Enums\MasterDataType;
use App\Models\Competency;
use App\Models\CompetencyImplementation;
use App\Models\CompetencyType;
use App\Models\DevelopmentProgram;
use App\Models\IndividualDevelopmentPlan;
use App\Models\KeyBehavior;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(MasterDataType $type, array $data): Model
    {
        return DB::transaction(function () use ($type, $data) {
            /** @var Model $master */
            $master = $type->modelClass()::create($this->attributes($type, $data));

            $this->syncLinks($type, $master, $data);

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

            $master->fill($this->attributes($type, $data))->save();

            $this->syncLinks($type, $master, $data, $presentKeys);

            $this->cascadeRename($type, $previousName, $master->name_en);

            return $master;
        });
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
            ]),

            MasterDataType::ProficiencyLevel => $this->firstBlocker([
                [$master->competencies(), 'it is assigned to a competency'],
                [$master->keyBehaviors(), 'it still has key behaviors'],
                [DevelopmentProgram::where('proficiency_level_id', $master->id), 'it is assigned to a development program'],
                [$master->implementations(), 'it is used in a master implementation'],
            ]),

            MasterDataType::CompetencyName => $this->firstBlocker([
                [$master->implementations(), 'it is used in a master implementation'],
            ]),

            MasterDataType::KeyBehavior, MasterDataType::Training, MasterDataType::DevelopmentProgram => null,

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

        return $attributes + match ($type) {
            MasterDataType::KeyBehavior => [
                // The level a behavior belongs to. Editing may move it.
                'proficiency_level_id' => $data['proficiency_level_id'],
            ],

            MasterDataType::CompetencyName => [
                'competency_type_id' => $data['competency_type_id'] ?? null,
            ],

            MasterDataType::DevelopmentProgram => $this->programAttributes($data),

            default => [],
        };
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
