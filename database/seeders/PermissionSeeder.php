<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

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
            // One permission per data type, read by ImportController::dataTypesFor().
            // Talent data — only Competency Assessment has an importer so far;
            // the others are kept for when theirs land.
            ['name' => 'import_competency_assessment', 'label' => 'Import Competency Assessment', 'group' => 'Import Center', 'section' => 'Import Talent Data'],
            ['name' => 'import_data_master', 'label' => 'Import Data Master (Matrix Grade)', 'group' => 'Import Center', 'section' => 'Import Talent Data'],
            ['name' => 'import_idp', 'label' => 'Import Individual Development Program', 'group' => 'Import Center', 'section' => 'Import Talent Data'],
            ['name' => 'import_talent_box', 'label' => 'Import Talent Box & Potential', 'group' => 'Import Center', 'section' => 'Import Talent Data'],
            ['name' => 'import_proposed_grade', 'label' => 'Import Proposed Grade', 'group' => 'Import Center', 'section' => 'Import Talent Data'],
            ['name' => 'import_succession', 'label' => 'Import Succession', 'group' => 'Import Center', 'section' => 'Import Talent Data'],
            // Master data.
            ['name' => 'import_competency_type', 'label' => 'Import Master Competency Type', 'group' => 'Import Center', 'section' => 'Import Master Data'],
            ['name' => 'import_competency', 'label' => 'Import Master Competency', 'group' => 'Import Center', 'section' => 'Import Master Data'],
            ['name' => 'import_training', 'label' => 'Import Master Training', 'group' => 'Import Center', 'section' => 'Import Master Data'],
            ['name' => 'import_development_program', 'label' => 'Import Master Development', 'group' => 'Import Center', 'section' => 'Import Master Data'],
            ['name' => 'import_review_tools', 'label' => 'Import Review Tools', 'group' => 'Import Center', 'section' => 'Import Master Data'],
            ['name' => 'delete_all_import_logs', 'label' => 'Delete All Import Logs', 'group' => 'Import Center', 'section' => 'Delete'],

            // Facecard — each view_* reveals one profile field (on screen and in
            // the PDF); the matching input_* implies it. See FacecardVisibility.
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

            // Master Data + IDP Settings — one permission per screen, covering the
            // screen AND its writes (see MasterDataType::permission() for the
            // shared master endpoints).
            ['name' => 'manage_competency_type', 'label' => 'Manage Master Competency Type', 'group' => 'Master Data', 'section' => 'Manage'],
            ['name' => 'manage_competency', 'label' => 'Manage Master Competency', 'group' => 'Master Data', 'section' => 'Manage'],
            ['name' => 'manage_master_implementation', 'label' => 'Manage Master Implementation', 'group' => 'Master Data', 'section' => 'Manage'],
            ['name' => 'manage_development_model', 'label' => 'Manage Development Model', 'group' => 'IDP Settings', 'section' => 'Manage'],
            ['name' => 'manage_master_training', 'label' => 'Manage Master Training', 'group' => 'IDP Settings', 'section' => 'Manage'],
            ['name' => 'manage_master_development', 'label' => 'Manage Master Development', 'group' => 'IDP Settings', 'section' => 'Manage'],
            ['name' => 'manage_review_tools', 'label' => 'Manage Review Tools', 'group' => 'IDP Settings', 'section' => 'Manage'],
            ['name' => 'view_approval_setting', 'label' => 'View & Manage Approval Setting', 'group' => 'Admin', 'section' => 'View'],

            // User Guide
            ['name' => 'manage_user_guide', 'label' => 'Input User Guide', 'group' => 'User Guide', 'section' => 'Input'],
            ['name' => 'view_admin_guide', 'label' => 'View Admin Guideline', 'group' => 'User Guide', 'section' => 'View'],

            // Data Access Permission — self-service access to a user's OWN data,
            // split by whether they are an Individual Contributor (no direct
            // reports) or a People Manager (has direct reports).
            ['name' => 'ic_view_facecard', 'label' => 'View Facecard', 'group' => 'Data Access Permission', 'section' => 'Individual Contributor'],
            ['name' => 'ic_download_facecard', 'label' => 'Download Facecard', 'group' => 'Data Access Permission', 'section' => 'Individual Contributor'],
            ['name' => 'ic_view_idp', 'label' => 'View IDP', 'group' => 'Data Access Permission', 'section' => 'Individual Contributor'],
            ['name' => 'ic_download_idp', 'label' => 'Download IDP', 'group' => 'Data Access Permission', 'section' => 'Individual Contributor'],
            ['name' => 'pm_view_facecard', 'label' => 'View Facecard', 'group' => 'Data Access Permission', 'section' => 'People Manager'],
            ['name' => 'pm_download_facecard', 'label' => 'Download Facecard', 'group' => 'Data Access Permission', 'section' => 'People Manager'],
            ['name' => 'pm_view_idp', 'label' => 'View IDP', 'group' => 'Data Access Permission', 'section' => 'People Manager'],
            ['name' => 'pm_download_idp', 'label' => 'Download IDP', 'group' => 'Data Access Permission', 'section' => 'People Manager'],
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
