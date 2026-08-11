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
        $superadmin->syncPermissions(Permission::all());

        $superior = Role::firstOrCreate(['name' => 'Superior', 'guard_name' => 'web']);
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

        // Baseline self-service role. It is NOT mass-assigned (the corporate
        // user base is large and churns constantly) — instead it is granted
        // lazily on sign-in: SsoController / DevLoginController assign it to the
        // user on login if they don't already have it. It carries both the
        // Individual Contributor and People Manager data-access permissions; the
        // runtime applies whichever set fits the person (ic_* for their own
        // record, pm_* for their team) based on their direct reports. This keeps
        // "deny by default" safe: no role => no data access, but anyone who
        // actually logs in gets this baseline.
        $selfService = Role::firstOrCreate(['name' => 'Employee (Self-Service)', 'guard_name' => 'web']);
        $selfService->syncPermissions([
            'ic_view_facecard', 'ic_download_facecard', 'ic_view_idp', 'ic_download_idp',
            'pm_view_facecard', 'pm_download_facecard', 'pm_view_idp', 'pm_download_idp',
        ]);

        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
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
