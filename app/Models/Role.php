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
    // Roles live in the shared permission DB (sys_perm) and, unlike permissions,
    // are NOT partitioned by domain: one role is shared by every HCIS app and may
    // hold permissions from several domains. The User model reads from kpncorp,
    // so this connection MUST be set explicitly: Eloquent's newRelatedInstance()
    // copies the parent's connection onto a related model whose own connection
    // is unset, so without this the User->roles() relation (and the
    // model_has_roles pivot) would inherit kpncorp.
    protected $connection = 'sys_perm';

    protected $casts = [
        'business_unit' => 'array',
        'company' => 'array',
        'location' => 'array',
        'is_data_access' => 'boolean',
    ];

    /**
     * Spatie clears the pivot with a bare detach(), which would also strip the
     * permissions other apps granted this shared role. Only this app's
     * permissions are replaced: the relation is domain-scoped through
     * Permission's global scope. In a transaction, so an unknown permission name
     * (which givePermissionTo() throws on) leaves the role as it was.
     */
    public function syncPermissions(...$permissions)
    {
        if (! $this->exists) {
            return $this->givePermissionTo($permissions);
        }

        return $this->getConnection()->transaction(function () use ($permissions) {
            $this->permissions()->detach($this->permissions()->get());
            $this->setRelation('permissions', collect());

            return $this->givePermissionTo($permissions);
        });
    }
}
