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
    protected $casts = [
        'business_unit' => 'array',
        'company' => 'array',
        'location' => 'array',
    ];
}
