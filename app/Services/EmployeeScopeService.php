<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessCache;
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

    /** Data Access permissions (self, team) that open an employee's IDP. */
    public const IDP_VIEW = ['ic_view_idp', 'pm_view_idp'];

    // Per-request memos. The service is bound `scoped` (one instance per
    // request / queued job), and a page asks the same questions several times —
    // the facecard profile alone checks four capabilities for one employee.

    /** @var array<int|string, list<string>> user id => data-access permission names */
    private array $dataPermissions = [];

    /** @var array<string, Employee|null> employee_id => corporate row */
    private array $employees = [];

    /** @var array<int|string, list<string>> user id => reportee employee_ids */
    private array $teams = [];

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
    public function accessibleQuery(
        User $user,
        string $selfPermission,
        string $teamPermission,
        bool $includeTeam = true,
    ): Builder {
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
        // Off for "may manage" questions: team visibility lets a manager READ
        // their reports' records, not change them (see canManageIdp()).
        $hasTeam = $includeTeam && in_array($teamPermission, $dataPermissions);
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
     * Whether the user may work on an employee's IDP — add, edit, delete,
     * import and submit plans and results.
     *
     * This is the SAME rule that opens the IDP screen (and that the screen uses
     * for `canManage`), so whoever sees the plan's edit controls may use them,
     * and nobody can write a plan they cannot see. Approving / rejecting is
     * separate: the approval service only lets the current-layer approver act.
     */
    public function canManageIdp(User $user, string $employeeId): bool
    {
        // Their own plan, an admin whose scoped role covers the employee, or
        // a Superadmin — every visibility source EXCEPT the team one.
        $managesDirectly = $this->accessibleQuery($user, ...[...self::IDP_VIEW, false])
            ->where('employee_id', $employeeId)
            ->exists();

        if ($managesDirectly) {
            return true;
        }

        // Of the people above the employee, only the FIRST approver on their
        // chain looks after the plan. Team access (pm_view_idp) reaches L2 as
        // well, so on its own it only lets a manager read the plan and decide
        // what is theirs to decide — never add or edit. Layer 1 may manage
        // even when the data-access rules do not reach them at all (an
        // Approval Layer override naming someone outside the hierarchy).
        return filled($user->employee_id)
            && $user->employee_id !== $employeeId
            && app(ApprovalChainService::class)->isLayerOne((string) $user->employee_id, $employeeId);
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
        return $this->dataPermissions[$user->getKey()] ??= (function () use ($user) {
            $employee = $this->userEmployee($user);

            return collect($this->dataAccessRoles())
                ->filter(fn (object $role) => $this->dataRoleApplies($role, $employee))
                ->flatMap(fn (object $role) => $role->permissions)
                ->unique()
                ->values()
                ->all();
        })();
    }

    /**
     * Every data-access role as {business_unit, company, location, permissions}.
     * Read from the shared permission DB (slow round trips) and identical for
     * every user, so it is cached across requests; {@see AccessCache::flush()}
     * runs when a role is saved.
     *
     * @return list<object{business_unit: array, company: array, location: array, permissions: list<string>}>
     */
    private function dataAccessRoles(): array
    {
        return array_map(fn (array $role) => (object) $role, AccessCache::remember('data-access-roles', fn () => Role::query()
            ->where('is_data_access', true)
            ->with('permissions:id,name')
            ->get()
            ->map(fn (Role $role) => [
                'business_unit' => $role->business_unit ?? [],
                'company' => $role->company ?? [],
                'location' => $role->location ?? [],
                'permissions' => $role->permissions->pluck('name')->all(),
            ])
            ->all()));
    }

    /**
     * Whether a data-access role applies to the given employee — i.e. the
     * employee falls within every scope dimension the role sets (AND within a
     * role). A role with no scope at all applies to everyone.
     */
    private function dataRoleApplies(object $role, ?Employee $employee): bool
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

        $id = (string) $user->employee_id;

        if (! array_key_exists($id, $this->employees)) {
            $this->employees[$id] = Employee::where('employee_id', $id)->first();
        }

        return $this->employees[$id];
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

        // The user's own corporate row may be missing (an incomplete or sampled
        // master) while their reports still name them as manager — fall back to
        // the same manager_l1/l2 lookup reporteeIds() uses, so a gap in the
        // master never empties a manager's team.
        return $this->teams[$user->getKey()] ??= (
            $this->userEmployee($user)
                ?? (new Employee)->forceFill(['employee_id' => $user->employee_id])
        )->reporteeIds();
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
