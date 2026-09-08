<?php

namespace App\Services\Idp;

use App\Models\Competency;
use App\Models\CompetencyImplementation;
use App\Support\NormalizesInput;
use Illuminate\Http\Request;

/**
 * Validation for a master implementation — the mapping that says at which
 * proficiency levels a competency is rolled out, and to which corporate scope.
 *
 * Beyond the field rules it polices three things: the competency has to belong
 * to the chosen competency type, the mapping may not duplicate an existing one,
 * and it may only map masters that are switched on. That last check exempts
 * what the row already stores, so once a competency or level is deactivated,
 * editing an unrelated field (a grade, a position) on an existing row still
 * works.
 */
class ImplementationValidator
{
    use ChecksProficiencyLevels;
    use NormalizesInput;

    /**
     * @return array<string, mixed>
     */
    public function validate(Request $request, ?CompetencyImplementation $implementation = null): array
    {
        $data = $request->validate([
            'competency_type_id' => ['required', 'integer', 'exists:competency_types,id'],
            'competency_id' => ['required', 'integer', 'exists:competencies,id'],
            // An implementation can pin one or more proficiency levels.
            'proficiency_level_ids' => ['nullable', 'array'],
            'proficiency_level_ids.*' => ['integer', 'exists:competency_proficiency_levels,id'],
            // ...and scope to any number of grades (empty means every grade).
            'grades' => ['nullable', 'array'],
            'grades.*' => ['string', 'max:255'],
            // A mapping covers any number of business units; empty means it is
            // not narrowed to one.
            'business_units' => ['nullable', 'array'],
            'business_units.*' => ['string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'job_family' => ['nullable', 'string', 'max:255'],
            'function_name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
        ]);

        $data['proficiency_level_ids'] = $this->intList($data['proficiency_level_ids'] ?? []);
        $data['grades'] = $this->stringList($data['grades'] ?? []);
        $data['business_units'] = $this->stringList($data['business_units'] ?? []);
        // Absent means active, so an older caller keeps creating live mappings.
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        // The chosen competency must belong to the chosen competency type.
        $competency = Competency::find($data['competency_id']);

        if ($competency && (int) $competency->competency_type_id !== (int) $data['competency_type_id']) {
            $this->fail('competency_id', 'The selected competency does not belong to the chosen competency type.');
        }

        $this->assertNotDuplicate($data, $implementation);
        $this->assertMastersActive($data, $competency, $implementation);

        return $data;
    }

    /**
     * Guard against a duplicate implementation for the same competency + scope.
     *
     * Grades are a list on the row, so they are not part of the key: one row
     * per competency + org scope carries every grade it covers. Business units
     * are a list too, and two rows that differ only by which units they cover
     * are genuinely different mappings — so the sets are compared, rather than
     * the row being keyed on a single unit.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNotDuplicate(array $data, ?CompetencyImplementation $implementation): void
    {
        $wanted = $data['business_units'];
        sort($wanted);

        $duplicate = CompetencyImplementation::query()
            ->when($implementation, fn ($q) => $q->whereKeyNot($implementation->id))
            ->where('competency_id', $data['competency_id'])
            ->where('job_family', $data['job_family'] ?? null)
            ->where('function_name', $data['function_name'] ?? null)
            ->where('position', $data['position'] ?? null)
            ->with('businessUnits')
            ->get()
            ->contains(function (CompetencyImplementation $existing) use ($wanted) {
                $units = $existing->businessUnits->pluck('business_unit')->all();
                sort($units);

                return $units === $wanted;
            });

        if ($duplicate) {
            $this->fail('competency_id', 'A master implementation with the same competency and scope already exists.');
        }
    }

    /**
     * An implementation may only map masters that are switched on: an inactive
     * competency, or an inactive proficiency level, would produce a mapping
     * that applies to nobody. The levels also have to be rungs of the mapped
     * competency's own ladder.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertMastersActive(
        array $data,
        ?Competency $competency,
        ?CompetencyImplementation $implementation,
    ): void {
        if ($competency === null) {
            return;
        }

        $unchanged = (int) $implementation?->competency_id === (int) $competency->id;

        if (! $competency->is_active && ! $unchanged) {
            $this->fail(
                'competency_id',
                "Cannot use '{$competency->name_en}': it is inactive."
            );
        }

        $this->assertLevelsBelongToCompetency($data['proficiency_level_ids'], (int) $competency->id);

        $this->assertLevelsActive(
            $data['proficiency_level_ids'],
            $implementation?->proficiencyLevels()->pluck('competency_proficiency_levels.id')->all() ?? [],
        );
    }
}
