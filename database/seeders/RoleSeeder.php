<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
