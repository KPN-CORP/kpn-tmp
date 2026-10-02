-- =============================================================================
-- kpn-tmp (Talent Management) -> shared role/permission database
-- Target: hcispanel_sys_permission   (connection `sys_perm` in this app)
--
-- The database is shared with other HCIS apps (extra-miles = extramiles.hcis.live).
--   * PERMISSIONS are per app: each carries its app's `domain_id`.
--   * ROLES are global: one role is shared by every app and may hold
--     permissions from several domains. Role names are unique across ALL apps
--     (case-insensitively), so our "Superadmin" IS extra-miles' "superadmin".
-- Safe to re-run: every statement is an upsert / INSERT IGNORE, and an existing
-- (shared) role is never modified.
--   Part 1  register this app's domain
--   Part 2  this app's permission catalogue (44)
--   Part 3  this app's roles + their permissions
--   Part 4  role assignments (model_has_roles)
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- Part 1 — domain (must equal DOMAIN_SYS_PERM in .env)
-- -----------------------------------------------------------------------------
INSERT INTO domains (name, is_active, created_at, updated_at)
VALUES ('talent-management.hcis.live', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE is_active = 1, updated_at = NOW();

SET @domain_id := (SELECT id FROM domains WHERE name = 'talent-management.hcis.live');

-- -----------------------------------------------------------------------------
-- Part 2 — permissions
-- -----------------------------------------------------------------------------
INSERT INTO permissions (domain_id, name, label, `group`, section, guard_name, created_at, updated_at) VALUES
    (@domain_id, 'view_admin_setting', 'View Admin Setting', 'Admin', 'View', 'web', NOW(), NOW()),
    (@domain_id, 'view_approval_setting', 'View & Manage Approval Setting', 'Admin', 'View', 'web', NOW(), NOW()),
    (@domain_id, 'ic_view_facecard', 'View Facecard', 'Data Access Permission', 'Individual Contributor', 'web', NOW(), NOW()),
    (@domain_id, 'ic_download_facecard', 'Download Facecard', 'Data Access Permission', 'Individual Contributor', 'web', NOW(), NOW()),
    (@domain_id, 'ic_view_idp', 'View IDP', 'Data Access Permission', 'Individual Contributor', 'web', NOW(), NOW()),
    (@domain_id, 'ic_download_idp', 'Download IDP', 'Data Access Permission', 'Individual Contributor', 'web', NOW(), NOW()),
    (@domain_id, 'pm_view_facecard', 'View Facecard', 'Data Access Permission', 'People Manager', 'web', NOW(), NOW()),
    (@domain_id, 'pm_download_facecard', 'Download Facecard', 'Data Access Permission', 'People Manager', 'web', NOW(), NOW()),
    (@domain_id, 'pm_view_idp', 'View IDP', 'Data Access Permission', 'People Manager', 'web', NOW(), NOW()),
    (@domain_id, 'pm_download_idp', 'Download IDP', 'Data Access Permission', 'People Manager', 'web', NOW(), NOW()),
    (@domain_id, 'input_successor_position', 'Input Successor to Position', 'Facecard', 'Input', 'web', NOW(), NOW()),
    (@domain_id, 'input_competency_assessment', 'Input Competency Assessment', 'Facecard', 'Input', 'web', NOW(), NOW()),
    (@domain_id, 'input_year_on_year', 'Input Year-on-Year 9-Box Mapping', 'Facecard', 'Input', 'web', NOW(), NOW()),
    (@domain_id, 'view_year_on_year', 'View Year-on-Year 9-Box Mapping', 'Facecard', 'View', 'web', NOW(), NOW()),
    (@domain_id, 'view_critical_position', 'View Critical Position', 'Facecard', 'View', 'web', NOW(), NOW()),
    (@domain_id, 'view_successor_type', 'View Successor Type', 'Facecard', 'View', 'web', NOW(), NOW()),
    (@domain_id, 'view_successor_position', 'View Successor Position', 'Facecard', 'View', 'web', NOW(), NOW()),
    (@domain_id, 'view_priority_dev', 'View Priority Development', 'Facecard', 'View', 'web', NOW(), NOW()),
    (@domain_id, 'view_proposed_grade', 'View Proposed Grade', 'Facecard', 'View', 'web', NOW(), NOW()),
    (@domain_id, 'manage_development_model', 'Manage Development Model', 'IDP Settings', 'Manage', 'web', NOW(), NOW()),
    (@domain_id, 'manage_master_training', 'Manage Master Training', 'IDP Settings', 'Manage', 'web', NOW(), NOW()),
    (@domain_id, 'manage_master_development', 'Manage Master Development', 'IDP Settings', 'Manage', 'web', NOW(), NOW()),
    (@domain_id, 'manage_review_tools', 'Manage Review Tools', 'IDP Settings', 'Manage', 'web', NOW(), NOW()),
    (@domain_id, 'delete_all_import_logs', 'Delete All Import Logs', 'Import Center', 'Delete', 'web', NOW(), NOW()),
    (@domain_id, 'import_competency_type', 'Import Master Competency Type', 'Import Center', 'Import Master Data', 'web', NOW(), NOW()),
    (@domain_id, 'import_competency', 'Import Master Competency', 'Import Center', 'Import Master Data', 'web', NOW(), NOW()),
    (@domain_id, 'import_training', 'Import Master Training', 'Import Center', 'Import Master Data', 'web', NOW(), NOW()),
    (@domain_id, 'import_development_program', 'Import Master Development', 'Import Center', 'Import Master Data', 'web', NOW(), NOW()),
    (@domain_id, 'import_review_tools', 'Import Review Tools', 'Import Center', 'Import Master Data', 'web', NOW(), NOW()),
    (@domain_id, 'import_competency_assessment', 'Import Competency Assessment', 'Import Center', 'Import Talent Data', 'web', NOW(), NOW()),
    (@domain_id, 'import_data_master', 'Import Data Master (Matrix Grade)', 'Import Center', 'Import Talent Data', 'web', NOW(), NOW()),
    (@domain_id, 'import_idp', 'Import Individual Development Program', 'Import Center', 'Import Talent Data', 'web', NOW(), NOW()),
    (@domain_id, 'import_talent_box', 'Import Talent Box & Potential', 'Import Center', 'Import Talent Data', 'web', NOW(), NOW()),
    (@domain_id, 'import_proposed_grade', 'Import Proposed Grade', 'Import Center', 'Import Talent Data', 'web', NOW(), NOW()),
    (@domain_id, 'import_succession', 'Import Succession', 'Import Center', 'Import Talent Data', 'web', NOW(), NOW()),
    (@domain_id, 'view_import_center', 'View Import Center Menu', 'Import Center', 'View', 'web', NOW(), NOW()),
    (@domain_id, 'manage_competency_type', 'Manage Master Competency Type', 'Master Data', 'Manage', 'web', NOW(), NOW()),
    (@domain_id, 'manage_competency', 'Manage Master Competency', 'Master Data', 'Manage', 'web', NOW(), NOW()),
    (@domain_id, 'manage_master_implementation', 'Manage Master Implementation', 'Master Data', 'Manage', 'web', NOW(), NOW()),
    (@domain_id, 'download_talent', 'Download & View Potential & Talent Box', 'Report', 'Download & View', 'web', NOW(), NOW()),
    (@domain_id, 'download_idp_progress', 'Download & View IDP Progress', 'Report', 'Download & View', 'web', NOW(), NOW()),
    (@domain_id, 'view_report_menu', 'View Report Menu', 'Report', 'View', 'web', NOW(), NOW()),
    (@domain_id, 'manage_user_guide', 'Input User Guide', 'User Guide', 'Input', 'web', NOW(), NOW()),
    (@domain_id, 'view_admin_guide', 'View Admin Guideline', 'User Guide', 'View', 'web', NOW(), NOW())
ON DUPLICATE KEY UPDATE label = VALUES(label), `group` = VALUES(`group`), section = VALUES(section), updated_at = NOW();

-- -----------------------------------------------------------------------------
-- Part 3 — roles. Superadmin / Superior / Admin are protected from deletion in
-- the Roles screen; 'Employee (Self-Service)' is the data-access baseline
-- (is_data_access = 1, no scope = applies to every user). An existing role of
-- the same name (e.g. extra-miles' 'superadmin') is reused, not changed.
-- -----------------------------------------------------------------------------
INSERT INTO roles (name, business_unit, company, location, is_data_access, guard_name, created_at, updated_at) VALUES
    ('Superadmin', NULL, NULL, NULL, 0, 'web', NOW(), NOW()),
    ('Superior', NULL, NULL, NULL, 0, 'web', NOW(), NOW()),
    ('Employee (Self-Service)', NULL, NULL, NULL, 1, 'web', NOW(), NOW()),
    ('Admin', NULL, NULL, NULL, 0, 'web', NOW(), NOW())
ON DUPLICATE KEY UPDATE id = id;  -- existing shared role: leave it as it is

-- role -> permissions: this domain's permissions, roles matched by name
-- (case-insensitive, so 'Superadmin' finds 'superadmin')

-- Superadmin (44)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id FROM permissions p JOIN roles r ON r.guard_name = p.guard_name
WHERE p.domain_id = @domain_id AND r.name = 'Superadmin';  -- every permission of this domain

-- Superior (21)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id FROM permissions p JOIN roles r ON r.guard_name = p.guard_name
WHERE p.domain_id = @domain_id AND r.name = 'Superior'
  AND p.name IN (
    'input_competency_assessment',
    'input_year_on_year',
    'view_year_on_year',
    'view_critical_position',
    'view_successor_type',
    'view_successor_position',
    'view_priority_dev',
    'view_proposed_grade',
    'manage_development_model',
    'manage_master_training',
    'manage_master_development',
    'manage_review_tools',
    'import_competency_type',
    'import_competency',
    'import_training',
    'import_development_program',
    'import_review_tools',
    'manage_competency_type',
    'manage_competency',
    'manage_master_implementation',
    'view_admin_guide'
  );

-- Employee (Self-Service) (8)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id FROM permissions p JOIN roles r ON r.guard_name = p.guard_name
WHERE p.domain_id = @domain_id AND r.name = 'Employee (Self-Service)'
  AND p.name IN (
    'ic_view_facecard',
    'ic_download_facecard',
    'ic_view_idp',
    'ic_download_idp',
    'pm_view_facecard',
    'pm_download_facecard',
    'pm_view_idp',
    'pm_download_idp'
  );

-- Admin (35)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, r.id FROM permissions p JOIN roles r ON r.guard_name = p.guard_name
WHERE p.domain_id = @domain_id AND r.name = 'Admin'
  AND p.name IN (
    'view_admin_setting',
    'view_approval_setting',
    'input_successor_position',
    'input_competency_assessment',
    'view_year_on_year',
    'view_critical_position',
    'view_successor_type',
    'view_successor_position',
    'view_priority_dev',
    'view_proposed_grade',
    'manage_development_model',
    'manage_master_training',
    'manage_master_development',
    'manage_review_tools',
    'delete_all_import_logs',
    'import_competency_type',
    'import_competency',
    'import_training',
    'import_development_program',
    'import_review_tools',
    'import_competency_assessment',
    'import_data_master',
    'import_idp',
    'import_talent_box',
    'import_proposed_grade',
    'import_succession',
    'view_import_center',
    'manage_competency_type',
    'manage_competency',
    'manage_master_implementation',
    'download_talent',
    'download_idp_progress',
    'view_report_menu',
    'manage_user_guide',
    'view_admin_guide'
  );

-- -----------------------------------------------------------------------------
-- Part 4 — role assignments
-- model_id is `users`.id on the KPNCORP database — the same users table every
-- HCIS app signs in against, which is why model_has_roles can be shared. These
-- ids come from the staging kpncorp (hcispanel_hc_stage); if the kpncorp this
-- app runs against numbers users differently, look them up there first:
--   SELECT id, employee_id FROM users WHERE employee_id IN ('01124040023','01124090037','01126010024');
-- -----------------------------------------------------------------------------
-- 01124040023  metta.saputra@kpn-corp.com
INSERT IGNORE INTO model_has_roles (role_id, model_type, model_id)
SELECT id, 'App\\Models\\User', 72113 FROM roles WHERE name = 'Superadmin' AND guard_name = 'web';

-- 01124090037  janice.olivia@kpn-corp.com
INSERT IGNORE INTO model_has_roles (role_id, model_type, model_id)
SELECT id, 'App\\Models\\User', 74036 FROM roles WHERE name = 'Superadmin' AND guard_name = 'web';

-- 01126010024  01126010024@kpn.co.id
INSERT IGNORE INTO model_has_roles (role_id, model_type, model_id)
SELECT id, 'App\\Models\\User', 70680116 FROM roles WHERE name = 'Superadmin' AND guard_name = 'web';

-- Done. Then, in the app:  php artisan permission:cache-reset && php artisan cache:clear
