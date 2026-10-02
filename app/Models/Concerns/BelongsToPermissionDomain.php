<?php

namespace App\Models\Concerns;

use App\Support\PermissionDomain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Scopes a Spatie role/permission model to this app's domain in the shared
 * permission database: every query (including Spatie's relation queries and its
 * cached permission map) only sees rows with this app's `domain_id`, and every
 * row created here is stamped with it.
 *
 * An unresolvable domain matches nothing rather than leaking another app's
 * roles, and refuses to create rows.
 */
trait BelongsToPermissionDomain
{
    public static function bootBelongsToPermissionDomain(): void
    {
        static::addGlobalScope('permission_domain', function (Builder $query) {
            $column = $query->getModel()->qualifyColumn('domain_id');
            $id = PermissionDomain::id();

            $id === null ? $query->whereRaw('1 = 0') : $query->where($column, $id);
        });

        static::creating(function (Model $model) {
            $model->setAttribute('domain_id', PermissionDomain::idOrFail());
        });
    }
}
