<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    /** Seeded roles that must not be deleted. */
    private const PROTECTED_ROLES = ['Superadmin', 'Superior', 'Admin'];

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
                'members' => User::role($role->name)->pluck('employee_id')->filter()->values(),
                'protected' => in_array($role->name, self::PROTECTED_ROLES, true),
            ]);

        return Inertia::render('Admin/Roles', [
            'roles' => $roles,
            'permissionGroups' => Permission::orderBy('group')->orderBy('name')->get()
                ->groupBy('group')
                ->map(fn ($group) => $group->map(fn ($p) => ['name' => $p->name, 'label' => $p->label ?? $p->name])),
        ]);
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
        $this->syncMembers($role, $data['members'] ?? []);

        return back()->with('success', "Role \"{$role->name}\" created.");
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validateRole($request, $role);

        $role->update([
            'name' => $data['name'],
            'business_unit' => $data['business_unit'] ?? [],
            'company' => $data['company'] ?? [],
            'location' => $data['location'] ?? [],
        ]);

        $role->syncPermissions($data['permissions'] ?? []);
        $this->syncMembers($role, $data['members'] ?? []);

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
        // Drop the role from anyone no longer selected.
        User::role($role->name)->whereNotIn('employee_id', $employeeIds)->get()
            ->each->removeRole($role);

        // Add it to the selected users that exist.
        User::whereIn('employee_id', $employeeIds)->get()
            ->each(fn (User $user) => $user->assignRole($role));
    }
}
