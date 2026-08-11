<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    /** Seeded roles that must not be deleted. */
    private const PROTECTED_ROLES = ['Superadmin', 'Superior', 'Admin', User::BASELINE_ROLE];

    public function index(): Response
    {
        $roles = Role::with('permissions:id,name')->orderBy('name')->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'business_unit' => $role->business_unit ?? [],
                'company' => $role->company ?? [],
                'location' => $role->location ?? [],
                'permissions' => $role->permissions->pluck('name'),
                // The baseline role is granted automatically on login, so its
                // membership is effectively "everyone who has signed in" — not
                // worth enumerating or managing by hand.
                'members' => $this->isDefaultRole($role)
                    ? []
                    : User::whereIn((new User)->getKeyName(), $this->userIdsWithRole($role))
                        ->pluck('employee_id')->filter()->values(),
                'protected' => in_array($role->name, self::PROTECTED_ROLES, true),
                'default' => $this->isDefaultRole($role),
            ]);

        return Inertia::render('Admin/Roles', [
            'roles' => $roles,
            'permissionGroups' => Permission::orderBy('group')->orderBy('section')->orderBy('name')->get()
                ->groupBy('group')
                ->map(fn ($group) => $group->map(fn ($p) => [
                    'name' => $p->name,
                    'label' => $p->label ?? $p->name,
                    'section' => $p->section ?? '',
                ])),
            'scopeOptions' => $this->scopeOptions(),
            'users' => User::whereNotNull('employee_id')->orderBy('name')
                ->get(['employee_id', 'name'])
                ->map(fn ($u) => ['value' => $u->employee_id, 'label' => "{$u->name} ({$u->employee_id})"]),
        ]);
    }

    /**
     * Distinct business unit / company / location values to scope a role by.
     *
     * @return array<string, array<int, string>>
     */
    private function scopeOptions(): array
    {
        try {
            return [
                'businessUnits' => Employee::whereNotNull('group_company')->distinct()->orderBy('group_company')->pluck('group_company')->all(),
                'companies' => Employee::whereNotNull('company_name')->distinct()->orderBy('company_name')->pluck('company_name')->all(),
                'locations' => Employee::whereNotNull('office_area')->distinct()->orderBy('office_area')->pluck('office_area')->all(),
            ];
        } catch (\Throwable $e) {
            return ['businessUnits' => [], 'companies' => [], 'locations' => []];
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateRole($request);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => 'web',
            'business_unit' => $data['business_unit'] ?? [],
            'company' => $data['company'] ?? [],
            'location' => $data['location'] ?? [],
        ]);

        $role->syncPermissions($data['permissions'] ?? []);
        if (! $this->isDefaultRole($role)) {
            $this->syncMembers($role, $data['members'] ?? []);
        }

        return back()->with('success', "Role \"{$role->name}\" created.");
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $isDefault = $this->isDefaultRole($role);
        $data = $this->validateRole($request, $role);

        $role->update([
            // The default role's name is referenced by the login provisioning
            // hook (User::BASELINE_ROLE), so it must not be renamed.
            'name' => $isDefault ? $role->name : $data['name'],
            'business_unit' => $data['business_unit'] ?? [],
            'company' => $data['company'] ?? [],
            'location' => $data['location'] ?? [],
        ]);

        $role->syncPermissions($data['permissions'] ?? []);
        if (! $isDefault) {
            $this->syncMembers($role, $data['members'] ?? []);
        }

        return back()->with('success', "Role \"{$role->name}\" updated.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            return back()->with('error', "The \"{$role->name}\" role cannot be deleted.");
        }

        $role->delete();

        return back()->with('success', 'Role deleted.');
    }

    /**
     * The auto-provisioned baseline role: undeletable, and its membership is not
     * managed by hand (granted on login instead).
     */
    private function isDefaultRole(Role $role): bool
    {
        return $role->name === User::BASELINE_ROLE;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRole(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role?->id)],
            'business_unit' => ['nullable', 'array'],
            'company' => ['nullable', 'array'],
            'location' => ['nullable', 'array'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            'members' => ['nullable', 'array'],
            'members.*' => ['string'],
        ]);
    }

    /**
     * Make exactly the given employees hold this role (matching existing users only).
     *
     * @param  array<int, string>  $employeeIds
     */
    private function syncMembers(Role $role, array $employeeIds): void
    {
        // Accounts that should hold the role (only users that actually exist).
        $targetUsers = User::whereIn('employee_id', $employeeIds)->get();

        // Drop the role from current holders no longer selected. The current
        // holders are read from the pivot directly (see userIdsWithRole).
        $staleIds = $this->userIdsWithRole($role)->diff($targetUsers->modelKeys());
        if ($staleIds->isNotEmpty()) {
            User::whereIn((new User)->getKeyName(), $staleIds->all())->get()
                ->each->removeRole($role);
        }

        // Grant it to the selected users. assignRole runs its pivot query on the
        // role's connection, so this works even though User is on kpncorp.
        $targetUsers->each(fn (User $user) => $user->assignRole($role));
    }

    /**
     * Primary keys of the users currently holding this role, read straight from
     * the pivot on the role's connection (mysql).
     *
     * User lives on the kpncorp connection while roles and the model_has_roles
     * pivot live on mysql, so Spatie's User::role() scope — a whereHas that
     * correlates a kpncorp query against mysql tables — cannot be resolved by
     * MySQL (see CLAUDE.md). Reading the pivot directly avoids the cross-DB join.
     *
     * @return Collection<int, int|string>
     */
    private function userIdsWithRole(Role $role): Collection
    {
        return DB::connection($role->getConnectionName())
            ->table(config('permission.table_names.model_has_roles'))
            ->where(config('permission.column_names.role_pivot_key') ?: 'role_id', $role->getKey())
            ->where('model_type', (new User)->getMorphClass())
            ->pluck(config('permission.column_names.model_morph_key'));
    }
}
