<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Core roles. Superadmin gets everything; Superior is the manager self-service
 * bundle; Admin is a business-unit PIC. Site/company-scoped roles are created at
 * runtime via the admin UI (they carry business_unit/company/location scopes).
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $superadmin = Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web']);
        $superadmin->forceFill(['is_data_access' => false])->save();
        $superadmin->syncPermissions(Permission::all());

        $superior = Role::firstOrCreate(['name' => 'Superior', 'guard_name' => 'web']);
        $superior->forceFill(['is_data_access' => false])->save();
        $superior->syncPermissions([
            'input_competency_assessment',
            'input_year_on_year',
            'view_year_on_year',
            'view_critical_position',
            'view_successor_type',
            'view_successor_position',
            'view_priority_dev',
            'view_proposed_grade',
            'view_idp_master',
            'view_admin_guide',
        ]);

        // Baseline data-access role (Data Access tab). It is a DATA role, so it is
        // never hand-assigned — it auto-applies to every user in its Access Scope.
        // With no scope set it applies to ALL users, granting each their own
        // (ic_*) and, for managers, their team's (pm_*) facecard/IDP access. Scope
        // it (e.g. to a business unit) to roll data access out per unit.
        $selfService = Role::firstOrCreate(['name' => 'Employee (Self-Service)', 'guard_name' => 'web']);
        $selfService->forceFill(['is_data_access' => true])->save();
        $selfService->syncPermissions([
            'ic_view_facecard', 'ic_download_facecard', 'ic_view_idp', 'ic_download_idp',
            'pm_view_facecard', 'pm_download_facecard', 'pm_view_idp', 'pm_download_idp',
        ]);

        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin->forceFill(['is_data_access' => false])->save();
        $admin->syncPermissions([
            'view_import_center',
            'import_competency_assessment',
            'import_data_master',
            'import_idp',
            'import_talent_box',
            'import_proposed_grade',
            'import_succession',
            'delete_all_import_logs',
            'view_report_menu',
            'download_talent',
            'download_idp_progress',
            'view_admin_setting',
            'view_idp_master',
            'view_approval_setting',
            'input_successor_position',
            'input_competency_assessment',
            'view_year_on_year',
            'view_critical_position',
            'view_successor_type',
            'view_successor_position',
            'view_priority_dev',
            'view_proposed_grade',
            'manage_user_guide',
            'view_admin_guide',
        ]);
    }
}
