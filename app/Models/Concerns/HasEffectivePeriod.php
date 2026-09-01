<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * An optional effective period on a master row: the window during which the
 * master may be picked for new work.
 *
 * Both ends are open-ended when null — a null start is effective from always,
 * a null end never expires — so a master with neither date set behaves exactly
 * like one that has no period at all.
 *
 * The period is deliberately *not* enforced by deleting or hiding rows: an IDP
 * that already names a master keeps resolving it. Only the pickers narrow to
 * what is effective, via {@see scopeEffective()}.
 *
 * Requires `effective_start_date` / `effective_end_date` date columns, both
 * cast to dates on the using model.
 */
trait HasEffectivePeriod
{
    /**
     * Limit to the masters whose window overlaps `[$from, $to]` — the ones that
     * are usable at some point during that span. A null `$to` leaves the span
     * open-ended.
     *
     * This is what relates two dated masters to each other: a proficiency level
     * can only serve a competency if the level is still usable somewhere inside
     * the competency's own effective window.
     */
    public function scopeEffectiveBetween(Builder $query, mixed $from, mixed $to = null): Builder
    {
        return $query
            // Not already expired when the span opens...
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_end_date')
                ->orWhereDate('effective_end_date', '>=', $from))
            // ...and not starting only after the span closes.
            ->when($to !== null, fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w
                    ->whereNull('effective_start_date')
                    ->orWhereDate('effective_start_date', '<=', $to)));
    }

    /**
     * Limit to the masters effective on the given date (default today) — the
     * single-day case of {@see scopeEffectiveBetween()}.
     */
    public function scopeEffective(Builder $query, mixed $on = null): Builder
    {
        $date = $on ?? today();

        return $query->effectiveBetween($date, $date);
    }

    /**
     * Whether this master is effective on the given date (default today).
     */
    public function isEffective(mixed $on = null): bool
    {
        $date = $on ?? today();

        if ($this->effective_start_date !== null && $this->effective_start_date->gt($date)) {
            return false;
        }

        return $this->effective_end_date === null || ! $this->effective_end_date->lt($date);
    }
}
