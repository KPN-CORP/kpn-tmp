<?php

namespace App\Services\Idp;

use App\Models\CompetencyImplementation;
use App\Models\User;
use App\Services\MasterStatusAudit;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Writing a master implementation: the row plus the three list-valued parts of
 * its scope (proficiency levels, grades, business units), and the activation
 * trail.
 *
 * Only *transitions* are recorded, which is why creating and updating are
 * separate entry points rather than one upsert: a mapping created switched off
 * is a transition worth recording, one created active is just the default, and
 * re-saving an unchanged form must add nothing.
 */
class ImplementationService
{
    public function __construct(private readonly MasterStatusAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor): CompetencyImplementation
    {
        return DB::transaction(function () use ($data, $actor) {
            $implementation = CompetencyImplementation::create($data);

            $this->syncScope($implementation, $data);

            if (! $implementation->is_active) {
                $this->recordStatus($implementation, false, $actor);
            }

            return $implementation;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CompetencyImplementation $implementation, array $data, ?User $actor): CompetencyImplementation
    {
        return DB::transaction(function () use ($implementation, $data, $actor) {
            $wasActive = (bool) $implementation->is_active;

            $implementation->update($data);
            $this->syncScope($implementation, $data);

            if ($wasActive !== (bool) $implementation->is_active) {
                $this->recordStatus($implementation, (bool) $implementation->is_active, $actor);
            }

            return $implementation;
        });
    }

    /**
     * Activate / deactivate one mapping from the list screen. Deactivating
     * keeps the row — it only stops the mapping applying from now on.
     */
    public function setActive(CompetencyImplementation $implementation, bool $active, ?User $actor): void
    {
        if ((bool) $implementation->is_active === $active) {
            return;
        }

        $implementation->forceFill(['is_active' => $active])->save();
        $this->recordStatus($implementation, $active, $actor);
    }

    /**
     * The activate / deactivate trail for one mapping, newest first. Read from
     * the audit log on disk, not from the database.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statusHistory(CompetencyImplementation $implementation): array
    {
        return $this->audit->for(MasterStatusAudit::IMPLEMENTATION, $implementation->id);
    }

    /**
     * Replace the list-valued parts of a mapping's scope.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncScope(CompetencyImplementation $implementation, array $data): void
    {
        $implementation->proficiencyLevels()->sync($data['proficiency_level_ids']);

        $this->replaceValues($implementation->grades(), 'grade', $data['grades']);
        $this->replaceValues(
            $implementation->businessUnits(),
            'business_unit',
            $data['business_units'],
        );
    }

    /**
     * Replace a child list of raw corporate strings with the submitted one.
     *
     * @param  list<string>  $values
     */
    private function replaceValues(HasMany $relation, string $column, array $values): void
    {
        $relation->delete();

        if ($values !== []) {
            $relation->createMany(array_map(fn (string $value) => [$column => $value], $values));
        }
    }

    /**
     * Log a mapping's activation change. A mapping has no name of its own, so
     * the competency it maps is what the history shows.
     */
    private function recordStatus(CompetencyImplementation $implementation, bool $active, ?User $actor): void
    {
        $this->audit->record(
            MasterStatusAudit::IMPLEMENTATION,
            $implementation->id,
            $implementation->competency?->name_en ?? "#{$implementation->id}",
            $active,
            $actor,
        );
    }
}
