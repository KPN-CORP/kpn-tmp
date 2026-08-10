<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides which employees a given user is allowed to see, mirroring facecard's
 * visibility rules:
 *
 *   1. Superadmin — the only role with unrestricted, org-wide visibility.
 *   2. Role scopes — a role may be limited to a set of business units /
 *      companies / locations. Within one role the scopes AND together; multiple
 *      scoped roles OR together. Roles that carry no scopes at all (e.g.
 *      Superior, Admin) do NOT widen visibility on their own — the user falls
 *      through to the manager / self rules below.
 *   3. Manager — no scoped role but sits above others in the org chart: their
 *      direct reports (L1/L2) plus themselves.
 *   4. Otherwise — only their own record.
 *
 * NOTE: unrestricted access used to be granted to *any* scope-less role, which
 * silently gave Superior/Admin (and any role whose scopes failed to persist)
 * full visibility. It is now gated on the Superadmin role name explicitly.
 */
class EmployeeScopeService
{
    /** The single role that may see every employee. */
    private const SUPERADMIN_ROLE = 'Superadmin';

    public function query(User $user): Builder
    {
        $query = Employee::query();
        $roles = $user->roles;

        // Only Superadmin sees everyone. Compared case-insensitively because the
        // DB collation matches role names case-insensitively (so the stored name
        // may be "superadmin" / "Superadmin" depending on how it was seeded).
        if ($roles->contains(fn ($role) => strcasecmp((string) $role->name, self::SUPERADMIN_ROLE) === 0)) {
            return $query;
        }

        // Roles that actually carry business-unit / company / location scopes.
        $scopedRoles = $roles->reject(fn ($role) => $this->roleIsUnscoped($role));

        if ($scopedRoles->isNotEmpty()) {
            return $query->where(function (Builder $outer) use ($scopedRoles) {
                foreach ($scopedRoles as $role) {
                    $outer->orWhere(function (Builder $q) use ($role) {
                        if (! empty($role->business_unit)) {
                            $q->whereIn('group_company', $role->business_unit);
                        }
                        if (! empty($role->company)) {
                            $q->whereIn('company_name', $role->company);
                        }
                        if (! empty($role->location)) {
                            $q->whereIn('office_area', $role->location);
                        }
                    });
                }
            });
        }

        if ($user->isManager()) {
            return $query->where(function (Builder $q) use ($user) {
                $q->where('manager_l1_id', $user->employee_id)
                    ->orWhere('manager_l2_id', $user->employee_id)
                    ->orWhere('employee_id', $user->employee_id);
            });
        }

        return $query->where('employee_id', $user->employee_id);
    }

    /**
     * Whether the signed-in user may view a specific employee.
     */
    public function canView(User $user, string $employeeId): bool
    {
        return $this->query($user)
            ->where('employee_id', $employeeId)
            ->exists();
    }

    private function roleIsUnscoped(object $role): bool
    {
        return empty($role->business_unit)
            && empty($role->company)
            && empty($role->location);
    }
}
