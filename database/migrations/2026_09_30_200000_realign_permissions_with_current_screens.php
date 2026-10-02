<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Bring the permission catalogue in line with the screens that exist now.
 *
 * - `view_idp_master` gated both admin menus AND every write on them, so it is
 *   split into one `manage_*` permission per screen: three under Master Data,
 *   four under IDP Settings. Each covers its screen and that screen's writes.
 * - The Import Center's master-data importers had no permission of their own;
 *   each data type now has an `import_<type>` permission that is actually read.
 *
 * Roles are created at runtime through the admin UI, so this runs as a
 * migration rather than a seeder: whoever could reach a screen before can still
 * reach it afterwards. Holders of `view_idp_master` (roles AND direct user
 * grants) receive every per-screen permission plus the five master importers,
 * and holders of `view_import_center` receive `import_competency_assessment` —
 * the one talent importer that worked without its own permission before.
 */
return new class extends Migration
{
    private const SCREENS = [
        // Master Data
        ['name' => 'manage_competency_type', 'label' => 'Manage Master Competency Type', 'group' => 'Master Data', 'section' => 'Manage'],
        ['name' => 'manage_competency', 'label' => 'Manage Master Competency', 'group' => 'Master Data', 'section' => 'Manage'],
        ['name' => 'manage_master_implementation', 'label' => 'Manage Master Implementation', 'group' => 'Master Data', 'section' => 'Manage'],
        // IDP Settings
        ['name' => 'manage_development_model', 'label' => 'Manage Development Model', 'group' => 'IDP Settings', 'section' => 'Manage'],
        ['name' => 'manage_master_training', 'label' => 'Manage Master Training', 'group' => 'IDP Settings', 'section' => 'Manage'],
        ['name' => 'manage_master_development', 'label' => 'Manage Master Development', 'group' => 'IDP Settings', 'section' => 'Manage'],
        ['name' => 'manage_review_tools', 'label' => 'Manage Review Tools', 'group' => 'IDP Settings', 'section' => 'Manage'],
    ];

    private const MASTER_IMPORTS = [
        ['name' => 'import_competency_type', 'label' => 'Import Master Competency Type', 'group' => 'Import Center', 'section' => 'Import Master Data'],
        ['name' => 'import_competency', 'label' => 'Import Master Competency', 'group' => 'Import Center', 'section' => 'Import Master Data'],
        ['name' => 'import_training', 'label' => 'Import Master Training', 'group' => 'Import Center', 'section' => 'Import Master Data'],
        ['name' => 'import_development_program', 'label' => 'Import Master Development', 'group' => 'Import Center', 'section' => 'Import Master Data'],
        ['name' => 'import_review_tools', 'label' => 'Import Review Tools', 'group' => 'Import Center', 'section' => 'Import Master Data'],
    ];

    /** The talent importers: same permissions, now filed under their own section. */
    private const TALENT_IMPORTS = [
        'import_competency_assessment', 'import_data_master', 'import_idp',
        'import_talent_box', 'import_proposed_grade', 'import_succession',
    ];

    public function up(): void
    {
        $now = now();
        $new = array_merge(self::SCREENS, self::MASTER_IMPORTS);

        foreach ($new as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name'], 'guard_name' => 'web'],
                $permission + ['created_at' => $now, 'updated_at' => $now],
            );
        }

        DB::table('permissions')->whereIn('name', self::TALENT_IMPORTS)
            ->update(['section' => 'Import Talent Data', 'updated_at' => $now]);

        $this->grantToHolders('view_idp_master', array_column($new, 'name'));
        $this->grantToHolders('view_import_center', ['import_competency_assessment']);

        // The pivots cascade on delete, so this also drops every assignment.
        DB::table('permissions')->where('name', 'view_idp_master')->delete();

        Artisan::call('permission:cache-reset');
    }

    public function down(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'view_idp_master', 'guard_name' => 'web'],
            ['label' => 'View IDP Master', 'group' => 'Admin', 'section' => 'View', 'created_at' => $now, 'updated_at' => $now],
        );

        // Any one screen was reachable only through the old permission.
        foreach (array_column(self::SCREENS, 'name') as $screen) {
            $this->grantToHolders($screen, ['view_idp_master']);
        }

        DB::table('permissions')
            ->whereIn('name', array_column(array_merge(self::SCREENS, self::MASTER_IMPORTS), 'name'))
            ->delete();
        DB::table('permissions')->whereIn('name', self::TALENT_IMPORTS)
            ->update(['section' => 'Import', 'updated_at' => $now]);

        // `import_competency_assessment` handed out by up() is left in place:
        // it cannot be told apart from a grant that predates this migration.

        Artisan::call('permission:cache-reset');
    }

    /**
     * Give every role and every user that holds `$source` the `$targets` too.
     *
     * @param  array<int, string>  $targets
     */
    private function grantToHolders(string $source, array $targets): void
    {
        $sourceId = DB::table('permissions')->where('name', $source)->where('guard_name', 'web')->value('id');
        if (! $sourceId) {
            return;
        }

        $targetIds = DB::table('permissions')->whereIn('name', $targets)->where('guard_name', 'web')->pluck('id');

        $roleIds = DB::table('role_has_permissions')->where('permission_id', $sourceId)->pluck('role_id');
        foreach ($roleIds as $roleId) {
            foreach ($targetIds as $targetId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $targetId, 'role_id' => $roleId]);
            }
        }

        $models = DB::table('model_has_permissions')->where('permission_id', $sourceId)->get(['model_type', 'model_id']);
        foreach ($models as $model) {
            foreach ($targetIds as $targetId) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $targetId,
                    'model_type' => $model->model_type,
                    'model_id' => $model->model_id,
                ]);
            }
        }
    }
};
