<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides which employees a given user is allowed to see, mirroring facecard's
 * visibility rules:
 *
 *   1. Role scopes — a role may be limited to a set of business units /
 *      companies / locations. Within one role the scopes AND together; multiple
 *      scoped roles OR together. A role with no scopes at all (e.g. Superadmin)
 *      means unrestricted access.
 *   2. Manager — no scoped role but sits above others in the org chart: their
 *      direct reports (L1/L2) plus themselves.
 *   3. Otherwise — only their own record.
 */
class EmployeeScopeService
{
    public function query(User $user): Builder
    {
        $query = Employee::query();
        $roles = $user->roles;

        if ($roles->isNotEmpty()) {
            // Any scope-less role grants unrestricted visibility.
            $hasUnrestrictedRole = $roles->contains(
                fn ($role) => $this->roleIsUnscoped($role),
            );

            if ($hasUnrestrictedRole) {
                return $query;
            }

            return $query->where(function (Builder $outer) use ($roles) {
                foreach ($roles as $role) {
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
