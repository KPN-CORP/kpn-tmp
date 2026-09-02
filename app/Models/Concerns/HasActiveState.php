<?php

namespace App\Models\Concerns;

use App\Services\MasterStatusAudit;
use Illuminate\Database\Eloquent\Builder;

/**
 * An active/inactive flag on a master row: whether it may be picked for new
 * work at all.
 *
 * Deactivating is deliberately *not* deleting or hiding: an IDP that already
 * names a master keeps resolving it, and the rows that already reference one
 * keep their reference. Only the pickers narrow to what is active, via
 * {@see scopeActive()}.
 *
 * Who flipped the flag and when is recorded outside the database by
 * {@see MasterStatusAudit}.
 *
 * Requires an `is_active` boolean column, cast to bool on the using model.
 */
trait HasActiveState
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
