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

        // Basic roles that actually carry business-unit / company / location
        // scopes (data-access roles are auto-applied, never a visibility source).
        $scopedRoles = $roles
            ->reject(fn ($role) => $role->is_data_access)
            ->reject(fn ($role) => $this->roleIsUnscoped($role));

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
     * Employees the user may access for a specific Data Access capability, as the
     * UNION of two independent sources:
     *
     *   1. Basic (assigned) roles that carry an object-visibility Access Scope —
     *      e.g. an HC Site admin whose role is scoped to a business unit sees
     *      that unit's employees. Superadmin sees everyone.
     *   2. Data Access roles that AUTO-APPLY to this user because their own
     *      employee record falls within the role's Access Scope (a data role with
     *      no scope applies to everyone). Such a role grants:
     *        - $selfPermission → the user's OWN record (Individual Contributor)
     *        - $teamPermission → the user's direct reportees (People Manager)
     *
     * A user reached by neither source sees nothing (deny by default).
     */
    public function accessibleQuery(User $user, string $selfPermission, string $teamPermission): Builder
    {
        $query = Employee::query();

        if ($this->isSuperadmin($user)) {
            return $query;
        }

        // (1) Object visibility from assigned, scoped BASIC roles.
        $scopedRoles = $user->roles
            ->reject(fn ($role) => $role->is_data_access)
            ->reject(fn ($role) => $this->roleIsUnscoped($role));

        // (2) Data-access capabilities that auto-apply to this user.
        $dataPermissions = $this->effectiveDataPermissions($user);
        $hasSelf = $user->employee_id && in_array($selfPermission, $dataPermissions);
        $hasTeam = in_array($teamPermission, $dataPermissions);
        $teamIds = $hasTeam ? $this->teamIds($user) : [];

        if ($scopedRoles->isEmpty() && ! $hasSelf && ! $hasTeam) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($scopedRoles, $hasSelf, $hasTeam, $teamIds, $user) {
            foreach ($scopedRoles as $role) {
                $outer->orWhere(fn (Builder $q) => $this->applyAttributeFilter($q, $role));
            }
            if ($hasSelf) {
                $outer->orWhere('employee_id', $user->employee_id);
            }
            if ($hasTeam && ! empty($teamIds)) {
                $outer->orWhereIn('employee_id', $teamIds);
            }
        });
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
     * The Data Access permission names that auto-apply to this user — the union
     * of every data-access role whose Access Scope contains the user's own
     * employee record (an unscoped data role applies to everyone).
     *
     * @return list<string>
     */
    public function effectiveDataPermissions(User $user): array
    {
        $employee = $this->userEmployee($user);

        return Role::query()
            ->where('is_data_access', true)
            ->with('permissions:id,name')
            ->get()
            ->filter(fn (Role $role) => $this->dataRoleApplies($role, $employee))
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether a data-access role applies to the given employee — i.e. the
     * employee falls within every scope dimension the role sets (AND within a
     * role). A role with no scope at all applies to everyone.
     */
    private function dataRoleApplies(Role $role, ?Employee $employee): bool
    {
        if ($this->roleIsUnscoped($role)) {
            return true;
        }

        if (! $employee) {
            return false;
        }

        if (! empty($role->business_unit) && ! in_array($employee->group_company, $role->business_unit)) {
            return false;
        }
        if (! empty($role->company) && ! in_array($employee->company_name, $role->company)) {
            return false;
        }
        if (! empty($role->location) && ! in_array($employee->office_area, $role->location)) {
            return false;
        }

        return true;
    }

    private function isSuperadmin(User $user): bool
    {
        return $user->roles->contains(
            fn ($role) => strcasecmp((string) $role->name, self::SUPERADMIN_ROLE) === 0
        );
    }

    private function userEmployee(User $user): ?Employee
    {
        if (blank($user->employee_id)) {
            return null;
        }

        return Employee::where('employee_id', $user->employee_id)->first();
    }

    /**
     * employee_ids of the user's direct reportees (their team), or [] if the
     * user has no employee record / no reports.
     *
     * @return list<string>
     */
    private function teamIds(User $user): array
    {
        $employee = $this->userEmployee($user);

        return $employee ? $employee->reporteeIds() : [];
    }

    /**
     * Apply one role's business unit / company / location filter (AND together).
     */
    private function applyAttributeFilter(Builder $q, Role $role): void
    {
        if (! empty($role->business_unit)) {
            $q->whereIn('group_company', $role->business_unit);
        }
        if (! empty($role->company)) {
            $q->whereIn('company_name', $role->company);
        }
        if (! empty($role->location)) {
            $q->whereIn('office_area', $role->location);
        }
    }

    /**
     * Constrain a query to the union of a set of scoped roles' filters (AND
     * within a role, OR across roles). Used by the legacy query() tier.
     *
     * @param  Collection<int, Role>  $scopedRoles
     */
    private function applyScopes(Builder $query, $scopedRoles): Builder
    {
        return $query->where(function (Builder $outer) use ($scopedRoles) {
            foreach ($scopedRoles as $role) {
                $outer->orWhere(fn (Builder $q) => $this->applyAttributeFilter($q, $role));
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
