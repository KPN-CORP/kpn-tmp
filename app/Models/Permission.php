<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Extends Spatie's Permission to pin it explicitly to the app DB (mysql).
 *
 * The explicit connection matters: Eloquent's newRelatedInstance() copies the
 * parent model's connection onto a related model whose own connection is unset.
 * Since the User model is on kpncorp, leaving this unset would make the Spatie
 * permission relations (and their pivots) inherit kpncorp. Setting it to mysql
 * keeps roles, permissions and every Spatie pivot on the app DB.
 */
class Permission extends SpatiePermission
{
    protected $connection = 'mysql';
}
