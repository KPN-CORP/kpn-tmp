<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Extends Spatie's Role to add the legacy data-scoping columns. A role may be
 * limited to a set of business units / companies / locations (stored as JSON
 * arrays); see EmployeeScopeService for how they filter the visible employees.
 */
class Role extends SpatieRole
{
    // Roles + permissions (and their pivots) live on the app DB (mysql), while
    // the User model reads from kpncorp. This connection MUST be set explicitly:
    // Eloquent's newRelatedInstance() copies the parent's connection onto a
    // related model whose own connection is unset, so without this the
    // User->roles() relation (and the model_has_roles pivot) would inherit
    // kpncorp instead of mysql.
    protected $connection = 'mysql';

    protected $casts = [
        'business_unit' => 'array',
        'company' => 'array',
        'location' => 'array',
        'is_data_access' => 'boolean',
    ];
}
