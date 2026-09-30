<?php

namespace App\Services\Idp;

use App\Models\DevelopmentModel;
use App\Models\IndividualDevelopmentPlan;
use Illuminate\Support\Collection;

/**
 * The plans an approval request covered, frozen as they were submitted — and
 * the difference between two such rounds.
 *
 * The approval log used to read the live plan rows, so a request decided weeks
 * ago showed whatever the plan says today, and two rounds of the same plan read
 * identically. Each request now carries its own copy, taken at submission.
 *
 * The copy is the exact row shape the approver's desk reads, so a snapshot and
 * a live plan are interchangeable wherever a request is displayed.
 */
class ApprovalSnapshot
{
    /**
     * Fields compared between rounds, in the order a reader meets them. `id` is
     * the key, not a field, and the model's id only matters through its name.
     */
    public const FIELDS = [
        'development_model',
        'competency_type',
        'competency_name',
        'development_program',
        'review_tools',
        'expected_outcome',
        'target',
        'uom',
        'time_frame_start',
        'time_frame_end',
        'realization_date',
        'achievement',
        'result_evidence',
    ];

    /**
     * @param  Collection<int, IndividualDevelopmentPlan>  $plans
     * @param  Collection<int, string>|null  $modelNames  model id => name, when the caller already has them
     * @return list<array<string, mixed>>
     */
    public static function of(Collection $plans, ?Collection $modelNames = null): array
    {
        $modelNames ??= DevelopmentModel::withTrashed()
            ->whereIn('id', $plans->pluck('development_model_id')->filter()->unique())
            ->pluck('name', 'id');

        return $plans->map(fn (IndividualDevelopmentPlan $plan) => [
            'id' => $plan->id,
            'development_model' => $modelNames[$plan->development_model_id] ?? null,
            'competency_type' => $plan->competency_type,
            'competency_name' => $plan->competency_name,
            'development_program' => $plan->development_program,
            'review_tools' => $plan->review_tools,
            'expected_outcome' => $plan->expected_outcome,
            'target' => $plan->target,
            'uom' => $plan->uom,
            'time_frame_start' => $plan->time_frame_start?->toDateString(),
            'time_frame_end' => $plan->time_frame_end?->toDateString(),
            'realization_date' => $plan->realization_date?->toDateString(),
            'achievement' => $plan->achievement,
            'result_evidence' => $plan->result_evidence,
        ])->values()->all();
    }

    /**
     * What changed from one round to the next, keyed on the plan id:
     *
     *  - `added`   — ids present now and not before;
     *  - `removed` — the earlier rows no longer covered (whole, so they can be shown);
     *  - `changed` — id => [field => [before, after]] for rows in both.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return array{added: list<int>, removed: list<array<string, mixed>>, changed: array<int, array<string, array{0: mixed, 1: mixed}>>}
     */
    public static function diff(array $before, array $after): array
    {
        $old = collect($before)->keyBy('id');
        $new = collect($after)->keyBy('id');

        $changed = [];

        foreach ($new as $id => $row) {
            if (! $old->has($id)) {
                continue;
            }

            $fields = [];

            foreach (self::FIELDS as $field) {
                $was = $old[$id][$field] ?? null;
                $now = $row[$field] ?? null;

                if (self::normalize($was) !== self::normalize($now)) {
                    $fields[$field] = [$was, $now];
                }
            }

            if ($fields) {
                $changed[$id] = $fields;
            }
        }

        return [
            'added' => $new->keys()->diff($old->keys())->values()->all(),
            'removed' => $old->except($new->keys()->all())->values()->all(),
            'changed' => $changed,
        ];
    }

    /**
     * Compare what a reader would see: 3 and 3.0 are the same target, and a
     * blank string is no value at all.
     */
    private static function normalize(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return is_numeric($value) ? (string) (float) $value : trim((string) $value);
    }
}
