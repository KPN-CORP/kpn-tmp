<?php

namespace App\Services\Idp\Rules;

use App\Models\Competency;
use App\Support\FailsValidation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The rules a competency's nested rows need — its sub-competencies and its own
 * proficiency ladder.
 *
 * Both lists are edited inline on the competency form, so both need the same
 * two things Laravel's per-field rules cannot express: a row the user added and
 * never filled in has to be dropped before the rules see it (it is a row they
 * changed their mind about, not an error), and a name repeated inside the
 * submitted rows has to be reported as a field message rather than surfacing as
 * the table's unique-index violation.
 */
class CompetencyMasterRules
{
    use FailsValidation;

    /**
     * Shape the submitted rows before validation runs.
     */
    public function prepare(Request $request): void
    {
        $this->dropUntouchedSubCompetencies($request);
        $this->dropUntouchedProficiencyLevels($request);
    }

    /**
     * Check what the per-field rules cannot, once the data is validated.
     *
     * @param  array<string, mixed>  $data
     */
    public function check(array $data, ?Model $master): void
    {
        $this->assertSubCompetencyNamesUnique($data);
        $this->assertProficiencyLadderConsistent($data);
        $this->assertRemovedLevelsUnused($data, $master);
    }

    /**
     * Drop the sub-competency rows that carry nothing at all - no name in
     * either language, no description in either.
     *
     * The row keys are deliberately NOT re-indexed: a validation error comes
     * back keyed by position (sub_competencies.2.name_en), and the form still
     * has the blank row on screen, so renumbering would pin an error to the
     * wrong row.
     */
    private function dropUntouchedSubCompetencies(Request $request): void
    {
        if (! $request->has('sub_competencies')) {
            return;
        }

        $fields = ['name_en', 'name_id', 'description_en', 'description_id'];

        $rows = collect((array) $request->input('sub_competencies'))
            ->map(fn ($row) => (array) $row)
            ->reject(fn (array $row) => collect($fields)->every(
                fn (string $field) => trim((string) ($row[$field] ?? '')) === ''
            ))
            ->all();

        $request->merge(['sub_competencies' => $rows]);
    }

    /**
     * Drop the ladder rows that carry nothing at all - no name or description in
     * either language and no key behavior with a name - plus, inside the rows
     * that stay, the key behaviors that are entirely blank.
     *
     * Same reasoning as the sub-competencies: a rung the user added and never
     * filled in is not an error, but one with anything in it has to name itself.
     * Keys are deliberately NOT re-indexed, so a validation error stays pinned
     * to the row it came from.
     */
    private function dropUntouchedProficiencyLevels(Request $request): void
    {
        if (! $request->has('proficiency_levels')) {
            return;
        }

        $any = fn (array $row, array $fields) => collect($fields)->contains(
            fn (string $field) => trim((string) ($row[$field] ?? '')) !== ''
        );

        $named = fn (array $row) => $any($row, ['name_en', 'name_id']);
        $filled = fn (array $row) => $any(
            $row,
            ['name_en', 'name_id', 'description_en', 'description_id'],
        );

        $rows = collect((array) $request->input('proficiency_levels'))
            ->map(function ($row) use ($named) {
                $row = (array) $row;

                $row['key_behaviors'] = collect((array) ($row['key_behaviors'] ?? []))
                    ->map(fn ($behavior) => (array) $behavior)
                    ->filter($named)
                    ->all();

                return $row;
            })
            ->filter(fn (array $row) => $filled($row) || $row['key_behaviors'] !== [])
            ->all();

        $request->merge(['proficiency_levels' => $rows]);
    }

    /**
     * A sub-competency name is unique inside its competency, which the table
     * enforces — so a duplicate in the submitted rows has to be caught here,
     * or it would surface as a database error instead of a field message.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertSubCompetencyNamesUnique(array $data): void
    {
        $names = collect($data['sub_competencies'] ?? [])
            ->map(fn ($row) => strtolower(trim((string) (((array) $row)['name_en'] ?? ''))))
            ->filter();

        if ($names->count() !== $names->unique()->count()) {
            $this->fail(
                'sub_competencies',
                'Each sub competency needs its own name; two of them are the same.'
            );
        }
    }

    /**
     * Two rungs may not share a name inside one competency, and two behaviors
     * may not share a name inside one rung - the tables enforce both, so a
     * duplicate has to be reported as a field message rather than a database
     * error.
     *
     * There is nothing to check about the ordering: both sequences are assigned
     * from the submitted row order, so they cannot collide.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertProficiencyLadderConsistent(array $data): void
    {
        $rows = collect($data['proficiency_levels'] ?? [])->map(fn ($row) => (array) $row);

        $names = $rows
            ->map(fn (array $row) => strtolower(trim((string) ($row['name_en'] ?? ''))))
            ->filter();

        if ($names->count() !== $names->unique()->count()) {
            $this->fail(
                'proficiency_levels',
                'Each proficiency level needs its own name; two of them are the same.'
            );
        }

        foreach ($rows as $row) {
            $behaviors = collect((array) ($row['key_behaviors'] ?? []))
                ->map(fn ($behavior) => strtolower(trim((string) (((array) $behavior)['name_en'] ?? ''))))
                ->filter();

            if ($behaviors->count() !== $behaviors->unique()->count()) {
                $this->fail(
                    'proficiency_levels',
                    "Each key behavior under '".($row['name_en'] ?? '?')."' needs its own name."
                );
            }
        }
    }

    /**
     * A rung another screen still points at cannot be dropped from a
     * competency's ladder: the row would go, taking the implementation /
     * training link with it and blanking the program that targeted it.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertRemovedLevelsUnused(array $data, ?Model $master): void
    {
        if (! $master instanceof Competency || ! array_key_exists('proficiency_levels', $data)) {
            return;
        }

        $kept = collect($data['proficiency_levels'] ?? [])
            ->map(fn ($row) => (int) (((array) $row)['id'] ?? 0))
            ->filter()
            ->all();

        if ($blocker = $this->removalBlocker($master, $kept)) {
            $this->fail('proficiency_levels', $blocker);
        }
    }

    /**
     * Why this competency's ladder cannot be cut back to the given rungs, or
     * null when it can.
     *
     * Only rungs actually removed are checked — everything kept, renamed or
     * renumbered is untouched. The three usages are read as exists subqueries
     * on the one query that fetches the removed rungs, so a ladder losing
     * several rows still costs a single round trip.
     *
     * Public because the Master Competency import replaces a ladder wholesale
     * too, and has to refuse the same removals — with a per-row message rather
     * than a validation error, which is the only reason it does not simply call
     * {@see check()}.
     *
     * @param  array<int, int>  $keptIds  ids of the rungs that survive
     */
    public function removalBlocker(Competency $competency, array $keptIds): ?string
    {
        $removed = $competency->proficiencyLevels()
            ->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds))
            ->withExists(['implementations', 'trainings', 'developmentPrograms'])
            ->get();

        foreach ($removed as $level) {
            $blocker = match (true) {
                (bool) $level->implementations_exists => 'it is used in a master implementation',
                (bool) $level->trainings_exists => 'it is assigned to a master training',
                (bool) $level->development_programs_exists => 'it is assigned to a development program',
                default => null,
            };

            if ($blocker !== null) {
                return "Cannot remove '{$level->name_en}': {$blocker}.";
            }
        }

        return null;
    }
}
