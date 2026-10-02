<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPermissionDomain;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Extends Spatie's Permission to read from the shared role/permission database
 * (`sys_perm`), scoped to this app's domain.
 *
 * The explicit connection matters: Eloquent's newRelatedInstance() copies the
 * parent model's connection onto a related model whose own connection is unset.
 * Since the User model is on kpncorp, leaving this unset would make the Spatie
 * permission relations (and their pivots) inherit kpncorp.
 */
class Permission extends SpatiePermission
{
    use BelongsToPermissionDomain;

    protected $connection = 'sys_perm';
}
