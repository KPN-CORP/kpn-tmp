<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;

/**
 * The full permission catalogue, carried over from the legacy app. Each entry
 * keeps its presentation metadata (label / group / section) so the admin Roles
 * UI can render them grouped.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Import Center
            ['name' => 'view_import_center', 'label' => 'View Import Center Menu', 'group' => 'Import Center', 'section' => 'View'],
            ['name' => 'import_competency_assessment', 'label' => 'Import Competency Assessment', 'group' => 'Import Center', 'section' => 'Import'],
            ['name' => 'import_data_master', 'label' => 'Import Data Master (Matrix Grade)', 'group' => 'Import Center', 'section' => 'Import'],
            ['name' => 'import_idp', 'label' => 'Import Individual Development Program', 'group' => 'Import Center', 'section' => 'Import'],
            ['name' => 'import_talent_box', 'label' => 'Import Talent Box & Potential', 'group' => 'Import Center', 'section' => 'Import'],
            ['name' => 'import_proposed_grade', 'label' => 'Import Proposed Grade', 'group' => 'Import Center', 'section' => 'Import'],
            ['name' => 'import_succession', 'label' => 'Import Succession', 'group' => 'Import Center', 'section' => 'Import'],
            ['name' => 'delete_all_import_logs', 'label' => 'Delete All Import Logs', 'group' => 'Import Center', 'section' => 'Delete'],

            // Facecard
            ['name' => 'input_successor_position', 'label' => 'Input Successor to Position', 'group' => 'Facecard', 'section' => 'Input'],
            ['name' => 'input_competency_assessment', 'label' => 'Input Competency Assessment', 'group' => 'Facecard', 'section' => 'Input'],
            ['name' => 'input_year_on_year', 'label' => 'Input Year-on-Year 9-Box Mapping', 'group' => 'Facecard', 'section' => 'Input'],
            ['name' => 'view_year_on_year', 'label' => 'View Year-on-Year 9-Box Mapping', 'group' => 'Facecard', 'section' => 'View'],
            ['name' => 'view_critical_position', 'label' => 'View Critical Position', 'group' => 'Facecard', 'section' => 'View'],
            ['name' => 'view_successor_type', 'label' => 'View Successor Type', 'group' => 'Facecard', 'section' => 'View'],
            ['name' => 'view_successor_position', 'label' => 'View Successor Position', 'group' => 'Facecard', 'section' => 'View'],
            ['name' => 'view_priority_dev', 'label' => 'View Priority Development', 'group' => 'Facecard', 'section' => 'View'],
            ['name' => 'view_proposed_grade', 'label' => 'View Proposed Grade', 'group' => 'Facecard', 'section' => 'View'],

            // Report
            ['name' => 'view_report_menu', 'label' => 'View Report Menu', 'group' => 'Report', 'section' => 'View'],
            ['name' => 'download_talent', 'label' => 'Download & View Potential & Talent Box', 'group' => 'Report', 'section' => 'Download & View'],
            ['name' => 'download_idp_progress', 'label' => 'Download & View IDP Progress', 'group' => 'Report', 'section' => 'Download & View'],

            // Admin
            ['name' => 'view_admin_setting', 'label' => 'View Admin Setting', 'group' => 'Admin', 'section' => 'View'],
            ['name' => 'view_idp_master', 'label' => 'View IDP Master', 'group' => 'Admin', 'section' => 'View'],

            // User Guide
            ['name' => 'manage_user_guide', 'label' => 'Input User Guide', 'group' => 'User Guide', 'section' => 'Input'],
            ['name' => 'view_admin_guide', 'label' => 'View Admin Guideline', 'group' => 'User Guide', 'section' => 'View'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name'], 'guard_name' => 'web'],
                $permission,
            );
        }

        // Refresh Spatie's cached permission map after seeding.
        Artisan::call('permission:cache-reset');
    }
}
