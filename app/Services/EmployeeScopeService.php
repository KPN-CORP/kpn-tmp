<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
            return $this->applyScopes($query, $scopedRoles);
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

    /**
     * Employees the user may access for a specific Data Access capability. The
     * broad tiers are unchanged — Superadmin sees everyone, scoped roles see
     * their business unit / company / location — and they bypass the ic_ / pm_
     * capability gate. Otherwise access is granted per permission:
     *
     *   - $selfPermission  → the user's OWN record (Individual Contributor tier).
     *   - $teamPermission  → the user's direct reportees (People Manager tier),
     *     resolved from Employee::reporteeIds(). "Team only": the manager's own
     *     record still comes from $selfPermission, not from the team grant.
     *
     * A user with neither permission (and no broad role) sees nothing.
     */
    public function accessibleQuery(User $user, string $selfPermission, string $teamPermission): Builder
    {
        $query = Employee::query();
        $roles = $user->roles;

        if ($roles->contains(fn ($role) => strcasecmp((string) $role->name, self::SUPERADMIN_ROLE) === 0)) {
            return $query;
        }

        $scopedRoles = $roles->reject(fn ($role) => $this->roleIsUnscoped($role));

        if ($scopedRoles->isNotEmpty()) {
            return $this->applyScopes($query, $scopedRoles);
        }

        $ids = collect();

        if ($user->employee_id && $user->can($selfPermission)) {
            $ids->push((string) $user->employee_id);
        }

        if ($user->can($teamPermission)) {
            $ids = $ids->merge($this->teamIds($user));
        }

        $ids = $ids->filter()->unique()->values();

        if ($ids->isEmpty()) {
            // No matching capability and no broad role — see nothing.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('employee_id', $ids->all());
    }

    /**
     * Whether the user may access one employee for the given capability.
     */
    public function canAccess(User $user, string $employeeId, string $selfPermission, string $teamPermission): bool
    {
        return $this->accessibleQuery($user, $selfPermission, $teamPermission)
            ->where('employee_id', $employeeId)
            ->exists();
    }

    /**
     * employee_ids of the user's direct reportees (their team), or [] if the
     * user has no employee record / no reports.
     *
     * @return list<string>
     */
    private function teamIds(User $user): array
    {
        if (blank($user->employee_id)) {
            return [];
        }

        $employee = Employee::where('employee_id', $user->employee_id)->first();

        return $employee ? $employee->reporteeIds() : [];
    }

    /**
     * Constrain a query to the union of a set of scoped roles' business unit /
     * company / location filters (AND within a role, OR across roles).
     *
     * @param  Collection<int, Role>  $scopedRoles
     */
    private function applyScopes(Builder $query, $scopedRoles): Builder
    {
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

    private function roleIsUnscoped(object $role): bool
    {
        return empty($role->business_unit)
            && empty($role->company)
            && empty($role->location);
    }
}
