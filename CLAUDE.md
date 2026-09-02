# CLAUDE.md

Guidance for Claude Code (claude.ai/code) working in this repository.

## Overview

**kpn-tmp** is a revamp of the legacy **facecard** app (`../facecard`, a Laravel 12 +
Blade + Alpine.js HR/talent app for KPN). The goal is to rebuild the same domain on a
modern stack — **Laravel 12 + Inertia + Vue 3 + TypeScript + Tailwind 4 + Spatie
laravel-permission** — with a cleaner, layered structure.

The domain is **HR / talent management**, centered on employee "facecards" (profile
summaries) plus talent workflows: Individual Development Plans (IDP), performance
appraisals, nine-box grids, competency assessments, result/succession summaries, Excel
import/export, and PDF generation.

### Source of truth for behavior
When implementing a feature, read the corresponding facecard code for business rules,
then re-implement it the modern way (do **not** copy Blade/Alpine verbatim):
- Controllers: `../facecard/app/Http/Controllers/` (`EmployeeController` is the hub).
- Views to port: `../facecard/resources/views/` (facecard_list, idp_*, report, nine_box_modal, etc.).
- Business logic lives inline in facecard controllers — extract it into **Services** here.

## Commands

```bash
composer install && npm install
composer run dev        # server + queue + vite (concurrently)
php artisan serve
npm run dev             # Vite HMR
npm run build
php artisan migrate --seed
php artisan test        # or composer test (clears config first)
./vendor/bin/pint       # format
```

## Architecture (target)

### Dual database (critical — inherited from facecard)
Two MySQL connections shape nearly every model:
- **`kpncorp`** — read-only corporate employee master (`employees` and related profile
  tables: formal_education, work_experience, training_certification, movement/promotion
  transactions). Configured in `config/database.php`; env `KPNCORP_DB_*`.
- **`mysql`** (`hcispanel_kpn_tmp`) — this app's own data: users, roles/permissions,
  appraisals, IDPs, competency assessments, result summaries, import logs.

Models on `kpncorp` (e.g. `Employee`) that relate to app-owned models **must hop
connections explicitly** with `->setConnection('mysql')` on the relation query. A missing
or wrong `setConnection` is the #1 source of "table not found" bugs. Mirror the pattern in
`../facecard/app/Models/Employees.php`.

### Auth
- **SSO (default):** external auth-service / Darwinbox token check → match `employee_id`
  to a local `User` → login. Ported in `SsoController` + `DbController` (XOR+base64 decrypt).
- **Dev login (local/QA):** key-gated employee impersonation.
Share `auth.user`, `permissions`, and the resolved `employee` to the frontend via Inertia
middleware (`HandleInertiaRequests::share`), consumed by `usePermission` / `useLocale`.

### Authorization — MODERNIZED
Use **Spatie laravel-permission** (already required in composer). Do NOT reintroduce
facecard's hand-rolled Gate registration. Map facecard's permission names
(`view_report_menu`, `view_admin_setting`, `manage_user_guide`, …) into a permission
seeder. Gate routes with `can:`/`permission:` middleware and add Policies where useful.
The Vue side gates nav items and actions through `usePermission().can()`.

### Layered structure (target)
```
app/
  Http/Controllers/   thin — delegate to Services, return Inertia::render / redirects
  Http/Requests/      FormRequest validation (scaffolded — fill in rules)
  Http/Resources/     ADD — shape Inertia props (EmployeeResource, IdpResource, …)
  Services/           ADD — business logic (MatrixGradeService, NineBoxService, IdpImportService)
  Imports/ Exports/   ADD — maatwebsite/excel classes
  Jobs/               ADD — GenerateIdpZip (bulk export) + status polling
  Policies/           Spatie-backed
  Models/             fillable/casts/relations, explicit setConnection() across DBs
resources/js/
  Pages/{Facecard,Idp,Appraisal,Competency,Report,Import,Admin}/   Inertia pages
  Components/{UI,Layout,Domain}/    Domain/ = NineBoxGrid, DataTable, FileUpload, Modal
  Composables/  Config/locales/{en,id}.ts
routes/web.php        real resource routes grouped by permission (no api.php)
```

### Composer deps
Installed: `barryvdh/laravel-dompdf` (PDF), `maatwebsite/excel` (Excel import/export).
`spatie/browsershot` was intentionally NOT used — it needs headless Chrome; dompdf covers
the PDF needs in pure PHP. Add browsershot only if a template needs full CSS/JS rendering.

## Conventions
- Frontend is TypeScript + `<script setup lang="ts">`. Match existing UI components in
  `resources/js/Components/UI/`. All user-facing strings are locale keys in
  `Config/locales/{en,id}.ts` (resolve via `useLocale().t`), never literals.
- Server-paginated lists (Inertia partial reloads), matching facecard's chunked lists.
- Keep controllers thin; put reusable/complex logic in `app/Services`.

## Scaffold cleanup — Phase 0 ✅ DONE
1. ✅ Renamed `CompetencyAssesment*` → `CompetencyAssessment*` (model, controller, both
   requests, policy, factory, seeder, migration; table `competency_assesments` →
   `competency_assessments`, column `assesment_date` → `assessment_date`). Verified via
   `composer dump-autoload` + class-resolution check.
2. ✅ Deleted `app/Models/PerformanceAppraisal copy.php`.
3. ✅ Fixed `PerformanceAppraisal::employee()`: `Employees::class` → `Employee::class`.

## Phase 1 — Data layer ✅ DONE
Verified with `migrate:fresh --seed` + tinker checks against local mysql.

**Correctness fixes**
- `employee_id` is now `string(25)` everywhere (competency_assessments,
  individual_development_plans, result_summaries) — it is a corporate HR id with leading
  zeros (e.g. `01124090037`); the scaffold's `unsignedBigInteger` would have corrupted it.
- `job_statuses.id` is a UUID (bulk-export jobs key on UUIDs).
- `Employee::user()` fixed to `hasOne(User, 'employee_id', 'employee_id')`.
- `User` connection corrected: app-owned tables (users, roles, appraisals…) live on
  `mysql` (the default), not `kpncorp`. `User` no longer overrides the connection.

**Schema alignment to facecard**
- development_models: `name` + `percentage` (70-20-10 weighting).
- competency_assessments: nullable tinyint scores + `proposed_grade` +
  `priority_for_development` + `period` string.
- matrix_grade_configs: tinyint mins + unique(period, grade_level).
- Added `import_logs` table; added `label/group/section` to Spatie `permissions`; added
  `business_unit/company/location` scope columns to Spatie `roles`.

**Models** — all fleshed out with `$fillable`/`$guarded`/`$casts`/relations. Cross-connection
relations on `Employee` hop with `->setConnection('mysql')` (developmentPlans,
competencyAssessments, resultSummary, user). `PerformanceAppraisal` intentionally stays on
`kpncorp` (it is corporate data, no migration creates it here).

**Seeders** — PermissionSeeder (24 perms w/ metadata), RoleSeeder (Superadmin=all, Superior,
Admin), DevelopmentModelSeeder (70-20-10), MatrixGradeConfigSeeder (2026 grades). Admin user
`admin@kpn.co.id` / `password` gets the Superadmin role.

**Later fix** — the `users` table now has an `employee_id` (string 25, nullable, unique). The
default Laravel migration lacked it even though the whole app keys on `users.employee_id`;
added when the sample seeders surfaced it.

## Sample data & how to test (local)
`php artisan migrate:fresh --seed` also loads a realistic dataset (both seeders skip
gracefully if kpncorp is unreachable):
- **`EmployeeSampleSeeder`** — ~286 real employees into the **kpncorp** `employees` table
  (schema + data in `database/seeders/{schema,data}/employees.sql`, straight from the legacy
  dump) and creates the corporate `performance_appraisals` table (9-box target).
- **`SampleDataSeeder`** — app-owned talent data for ~27 employees: users (password
  `password`), IDP master data (competencies/programs/review tools, linked), IDPs, competency
  assessments (matrix grade computed by `MatrixGradeService`), succession summaries, and
  2025/2026 9-box rows.

Sign in as `admin@kpn.co.id` / `password` (Superadmin — sees all 286). Named superadmins:
`01124090037` (Janice) and `01124040023` (Metta); their user emails are
`<employee_id>@kpn.test` / `password`. Dev-login impersonation works once `DEV_LOGIN_KEY` is
set in `.env`.

⚠️ **Cross-connection query caveat**: eager/lazy loading a cross-DB relation works
(`$employee->developmentPlans()->get()`), but `has()`/`whereHas()` on one does NOT — MySQL
can't correlate a subquery across two databases. Query the related model directly and filter
by `employee_id` instead (which is what the controllers do).

## Phase 2 — Auth & shell ✅ DONE
Verified with `route:list` (permission middleware attached) + `npm run build`.

**Authorization wiring**
- Spatie middleware aliases registered in `bootstrap/app.php` (`permission`, `role`,
  `role_or_permission`). Feature routes gate with `->middleware('permission:…')`.
- `HandleInertiaRequests` shares `auth.user`, `auth.employee` (resolved from kpncorp,
  guarded so a missing connection never breaks the shell), and `permissions`.

**Auth paths**
- **SSO** (`SsoController@dbauth`, route `sso/dbauth`) — modernized: uses the `Http` facade
  + `config('services.sso.*')` (no hard-coded secrets, no SweetAlert). Decrypts
  base64→XOR→base64→JSON, verifies the token, logs in the matching `User`.
- **Dev-login** (`Auth\DevLoginController`, Inertia) — key-gated employee impersonation:
  `/dev-login` (access key) → `/dev-login/employees` (live search picker) → impersonate.
  Disabled unless `DEV_LOGIN_KEY` is set (`config('services.dev_login.key')`).
- Standard email/password login (Breeze scaffold) still works for the seeded admin.
- Removed the dead `DbController` (duplicate SSO-decrypt).

**Shell / navigation**
- Real permission-gated HR menu in `resources/js/Config/navigation.ts`
  (Main: dashboard/facecard/idp · Talent: report/import-center · Administration:
  idp-setting/roles/user-guide), with matching `en`/`id` locale keys.
- `routes/web.php` has the real paths + route names + permission gates; feature screens
  still render the shared `Placeholder` page until Phase 3 swaps in real controllers.
- `.env.example` updated with the dual-DB + SSO + dev-login variables.

### Still open for later phases
- Composer deps (`maatwebsite/excel`, `dompdf`, `browsershot`) intentionally NOT installed
  yet — add each when its feature (Excel/PDF) lands.
- Site/company-scoped roles (the many `HC Site …` / `PIC/Admin …` roles) are runtime data
  created via the admin UI, not seeded.

## Phase 3.1 — Facecard list + profile ✅ DONE (code)
Static-verified (lint, autoload, `route:list`, `npm run build`). NOTE: runtime/DB checks
were NOT run — the local MySQL (app + kpncorp) was unreachable during this build; verify
`/facecard` and `/employee/{id}` once the DBs are up (kpncorp especially, since the list
reads the `employees` master).

- **`EmployeeScopeService`** (`app/Services`) — the reusable visibility rule: scope-less
  role (Superadmin) ⇒ all; scoped role(s) ⇒ business_unit/company/location (AND within a
  role, OR across roles); else manager ⇒ direct reports + self; else ⇒ self only.
- **`App\Models\Role`** extends Spatie's Role and casts `business_unit/company/location`
  to arrays; `config/permission.php` now points `models.role` at it (needed so the scope
  columns are arrays on read/write — the admin UI will rely on this too).
- **`EmployeeController`** (thin): `index()` = paginated + filtered list (search, business
  unit, job level, designation) via the service; `show()` = profile (employee header +
  formal education / work experience / trainings, read defensively from kpncorp).
  `EmployeeResource` shapes the props. Routes `facecard.list` + `employee.profile` are live.
- **Vue**: `Pages/Facecard/Index.vue` (filters + server pagination via Inertia partial
  reload) and `Profile.vue`, plus reusable `Components/Domain/DataTable.vue`; `facecard`
  locale keys added to `en`/`id`.
- Remaining for Facecard: single + bulk PDF export (needs dompdf/browsershot), photo
  up/download, and the talent tabs on the profile (competency/IDP/nine-box) which arrive
  with Phases 3.3–3.4.

## Phase 3.2 — IDP (CRUD + settings) ✅ DONE (code)
Static-verified (lint, autoload, `route:list`, `npm run build`). Runtime/DB checks NOT run
(MySQL unreachable this session). Excel import + PDF export are DEFERRED — they need
`maatwebsite/excel` / dompdf / browsershot, not yet installed.

- **`IdpController`** (thin): `index` (employee list → manage), `show` (manage page),
  `store`/`update`/`destroy` plans — each authorized via `EmployeeScopeService::canView`.
  `IdpService` builds the manage-page data (models, plans grouped by model, master-driven
  dropdown options, and the competency→programs map for the soft-competency cascade).
- **`StoreIndividualDevelopmentPlanRequest`** (+ `Update` subclass) carry facecard's rules,
  including the `withValidator` soft-competency program-validity check.
- **`IdpSettingController`**: development models CRUD (`SumPercentageCheck` ≤100, delete
  guards, replace-with reassign) + master data CRUD for `competency_name` /
  `development_program` / `review_tools`, with the competency↔program `related_program`
  linking kept in sync on create/update/delete. *(Superseded by Phase 5.3 — the master
  tables were split apart and `related_program` is now a pivot.)*
- **`App\Rules\SumPercentageCheck`** (modern `ValidationRule`).
- **Vue**: `Pages/Idp/Index.vue`, `Manage.vue` (plans grouped by model, add/edit/delete via
  modal, soft-competency program filtering), `Settings.vue`; reusable
  `Components/Domain/Modal.vue`; `idp` locale keys in `en`/`id`.
- Routes wired under the existing gates (idp.* open to authed users; idp-setting.* behind
  `permission:view_idp_master`).

## Phase 3.3 — Performance Appraisal + Nine-box ✅ DONE (code)
Static-verified (lint, autoload, `route:list`, `npm run build`). Runtime/DB NOT tested.
NOTE: `PerformanceAppraisal` is on the **kpncorp** connection and this feature *writes*
`potential`/`talent_box` back to `performance_appraisals` (matching facecard — the grade is
corporate, the 9-box mapping is added here). Verify writes are permitted on that DB.

- **`PerformanceAppraisalController`**: `store` (create a year's 9-box: appraisal_year +
  potential High/Medium/Low + talent_box; rejects duplicate years), `update` (potential /
  talent_box), `destroy` (resets if a corporate grade exists, else deletes). Scoped via
  `EmployeeScopeService::canView`; routes `ninebox.*` gated `permission:input_year_on_year`.
- **`EmployeeController@show`** now also loads `appraisals` + `canInputNineBox` for the
  profile.
- **Vue**: `Components/Domain/NineBoxGrid.vue` (3×3 talent grid, Potential × Performance,
  highlights the box by its number) and `NineBoxSection.vue` (year list + grid + add/edit/
  delete via `Modal`), embedded in `Facecard/Profile.vue`. `appraisal` locale keys en/id.
- The 9 talent boxes: Stars(1) High Potentials(2) High Impact Performers(3) Trusted
  Professional(4) Potential Gems(5) Core Players(6) Effective Employee(7) Inconsistent
  Performers(8) Deadwood(9).

## Phase 3.4 — Competency Assessment + Result Summary ✅ DONE (code)
Fully app-owned (mysql). Static-verified (lint, autoload, route:list, build).
- **`MatrixGradeService`** — target grade = highest `MatrixGradeConfig.grade_level` for the
  period whose nine `*_min` thresholds are all met by the scores.
- **`CompetencyAssessmentController@store`** (`StoreCompetencyAssessmentRequest`, scores
  0-4) — `updateOrCreate` on employee+period; `matrix_grade` computed **server-side**.
- **`ResultSummaryController@store`** (`StoreResultSummaryRequest`) — `updateOrCreate`.
- `EmployeeController@show` loads competency assessments, result summary, matrix configs,
  and `canInputCompetency`/`canInputSuccession`.
- **Vue**: `Components/Domain/CompetencySection.vue` (scoring form with a **live** matrix
  grade mirroring the service) + `ResultSummarySection.vue`, both in `Facecard/Profile.vue`.
  `competency`/`result` locale keys. Routes `competency.store`, `resultSummary.store`.

## Phase 3.5 — Import Center ✅ DONE (code, parsing deferred)
- **`ImportController`**: `index` (paginated logs), `processImport` (validates type + file,
  stores upload, writes an `ImportLog` as **Pending**), `download`, `destroy`, `destroyAll`
  (gated `delete_all_import_logs`). Group gated `permission:view_import_center`.
- **Vue**: `Pages/Import/Index.vue` (upload by data type + logs table w/ status/download/
  delete). `import` locale keys.
- ⚠️ Spreadsheet PARSING is deferred — needs `maatwebsite/excel`. Wire per-type Import
  classes into `processImport` when the dep lands; the upload/log/download flow already works.

## Phase 3.6 — Admin (Roles/Permissions) + User Guide ✅ DONE (code)
- **`RoleController`** (Spatie, uses `App\Models\Role` w/ array-cast scopes): index/store/
  update/destroy — name, `business_unit/company/location` scopes, permission sync, member
  (employee_id → User) sync; Superadmin/Superior/Admin protected from deletion. Gated
  `permission:view_admin_setting`.
- **`UserGuideController`**: index (view open to all) + store/download/destroy; upload gated
  `permission:manage_user_guide`.
- **Vue**: `Pages/Admin/Roles.vue` (role cards + create/edit modal w/ grouped permission
  matrix, scopes, members) and `Pages/UserGuide/Index.vue` (cards + upload modal).
  `roles`/`guide` locale keys.

## Phase 4 — Cross-cutting (Excel / PDF / queue) ✅ MOSTLY DONE
Deps installed (`barryvdh/laravel-dompdf`, `maatwebsite/excel`). Runtime-verified in
isolation: dompdf renders a real `%PDF-`, Excel facade + exports + job + ZipArchive resolve.
Full end-to-end still needs the DBs (kpncorp especially) + a queue worker.

- **PDF (dompdf)**: `resources/views/pdf/facecard.blade.php` + `idp.blade.php`;
  `EmployeeController@downloadPdf` (`/employee/{id}/pdf`) and `IdpController@downloadPdf`
  (`/idp/{id}/pdf`), with Download-PDF buttons on the profile and IDP manage pages.
- **Excel export (maatwebsite)**: `EmployeeExport` (scoped+filtered list →
  `/facecard/export`, carries current filters) and `IdpExport` (`/idp/{id}/export`), with
  Export-Excel buttons.
- **Excel import**: `CompetencyAssessmentImport` (ToCollection + heading row, derives the
  matrix grade, collects per-row errors) wired into `ImportController@processImport` for the
  `competency_assessment` type — parses now, writes a Success/Failed `ImportLog`. Other data
  types still log Pending until their importers are added.
- **Bulk export job**: `App\Jobs\GenerateIdpZip` (renders each visible employee's IDP PDF
  into one zip, progress tracked on the uuid `JobStatus`) + `IdpController@bulkDownload`
  / `bulkStatus` / `bulkFile`; the IDP list has a "Download all (PDF zip)" button that starts
  the job and polls (`fetch` with the `XSRF-TOKEN` cookie). Needs `php artisan queue:work`.

### Phase 4 — what's left
- **Tests & Policies** — Pest/PHPUnit feature tests + per-controller Policies are NOT written.
  Feature tests need the dual-connection test setup (point `mysql` AND `kpncorp` at a shared
  test DB; `phpunit.xml` already forces sqlite `:memory:` as the default). This is the main
  remaining Phase 4 work and is best done against a reachable DB.
- Importers for the non-competency data types (data_master, idp, talent_box, proposed_grade,
  succession); the Report screen (`/report` still a stub) and its export; employee photo
  upload/download. Optional: extract `FileUpload`/`FormField` reusable Vue components (upload
  and form fields are currently inline).

## Phase 5.1 — Approval Layers (per-employee) ✅ DONE (code + runtime-verified)
Per-employee approval chains — replaces facecard's hard-coded manager_l1/manager_l2 signature
step. **Only the SETTINGS screen is built here**; the runtime that consumes the chains
(submit → approve/reject, status tracking, notifications) is a later slice. **Design note:**
this replaced an earlier per-module "approval flow" design — the module concept (IDP /
Appraisal tabs) and the `approval_flows`/`approval_layers` tables were dropped. Approval is now
keyed **by employee**, matching the legacy "Layers" screen.

- **Tables** (app-owned, `mysql`): `approval_superiors` (`employee_id` unique + `layers` json
  **ordered array** of approver employee_ids + `updated_by`) and `approval_superior_histories`
  (`employee_id`, `layers` json array snapshot, `changed_by`/`changed_by_name`, `created_at`
  only). Layer ids are kpncorp employee_ids, intentionally NOT cross-DB FKs. Migrations:
  `..._replace_approval_flows_with_superiors` drops the old per-module tables; then
  `..._approval_superiors_dynamic_layers` swaps the fixed layer_1..5 columns for the json
  `layers` array so a chain can be **any length** (add/remove from the UI, no cap).
- **Effective chain**: each employee has an ordered list of explicit superior approvers. When
  no override row exists, the chain **defaults to the corporate `manager_l1_id` /
  `manager_l2_id`**. A saved override replaces the whole list. Defaulting lives in
  `ApprovalSettingController::effectiveLayerIds()`; `ApprovalSuperior::approverIds()` gives the
  ordered, non-empty approver list for the future runtime. Names are shown as
  `employee_id - name`, falling back to the bare id when the approver isn't in the (sampled)
  employee table.
- **Permission** `view_approval_setting` (Admin group) in `PermissionSeeder` + `Admin` role;
  Superadmin via `Permission::all()`. (No seeder for the chains — they default from the master
  and are created on first save/import.)
- **Controller** `ApprovalSettingController` (gated `permission:view_approval_setting`):
  `index` (scoped + searchable + paginated employee list via `EmployeeScopeService`, each row
  carrying effective L1..L5 with names resolved in one guarded query), `update` (upsert the 5
  layers + write a history snapshot; scoped by `canView`), `history` (JSON change log, newest
  first, names resolved), `searchEmployees` (live picker search), `import` (Excel/CSV bulk via
  `App\Imports\ApprovalLayerImport` — heading row `nik`/`employee_id` + `layer_1`..`layer_5`,
  upserts + audits each row). Request `UpdateApprovalSuperiorRequest`.
- **Vue** `Pages/Admin/ApprovalSetting.vue`: a table (#, NIK, Name, PT=`company_name`,
  Area=`office_area`, BU=`group_company`, Superior showing L1/L2, Actions) with server search,
  **sortable columns** (NIK/Name/PT/Area/BU via the `ReadsSort` trait), **PT/Area/BU filter
  dropdowns** (distinct values scoped to the user via `filterOptions`), and pagination; an
  **Update Superior** Drawer with a **dynamic** layer list (numbered rows, each
  a live employee picker, per-row remove + an "Add approval layer" button — no fixed count); a
  **History** Drawer; and an **Import Layer** Drawer. Reusable
  `Components/Domain/EmployeeSelect.vue` (live combobox, `employee_id - name` format, clearable)
  powers the pickers. Nav item `approvalSetting` (label "Approval Layer", `/approval-setting`);
  `approval` locale block in `en`/`id`.
- **Not yet built**: the approval runtime (submit/approve/reject + state), notifications, an
  import template download, and tests/policies.

## Build plan (phased)

**Phase 0 — Cleanup** (above): rename, delete leftovers, fix class refs, add perm seeder.

**Phase 1 — Data layer:** flesh out all models + relations (cross-connection); verify/port
migrations for every app-owned table (appraisals, result_summaries, import_logs, Spatie
tables); seeders for roles, permissions, matrix grade config, dev-login employees.

**Phase 2 — Auth & shell:** finish SSO + dev-login end-to-end; share auth/permissions/
employee via Inertia middleware; real permission-gated navigation.

**Phase 3 — Feature migration (Blade → Vue), by priority:**
1. Facecard — list (paginated + filters), profile page, single + bulk PDF export.
2. IDP — table CRUD, IDP settings (models/masters), Excel import, PDF template.
3. Performance Appraisal + Nine-box grid (Vue component), store/delete.
4. Competency Assessment + Result Summary — scoring form, matrix-grade calc (MatrixGradeService).
5. Import Center — upload, process, logs, download.
6. Admin — Roles/permissions UI, User Guide.

**Phase 4 — Cross-cutting:** Excel Imports/Exports; `GenerateIdpZip` queue job + status
polling; reusable Vue (DataTable, Modal, FileUpload, NineBoxGrid, FormField); complete i18n
keys; Pest/PHPUnit feature tests + policies per controller.

## Phase 5.3 — Normalize the IDP master data ✅ DONE (code + runtime-verified)
`development_plan_masters` was single-table inheritance: a `type` discriminator over seven
unrelated entities sharing 21 mostly-nullable columns. It is **gone**, split one table per
entity. All 409 live rows migrated and verified; the rollback round-trips byte-identically.

- **New tables**: `competency_types`, `proficiency_levels`, `key_behaviors`, `competencies`,
  `development_programs`, `review_tools`, `trainings`, `competency_implementations`, plus the
  link tables `competency_proficiency_level`, `competency_key_behavior`,
  `competency_development_program`, `development_program_grades`,
  `implementation_proficiency_level`. Models: `Competency`, `CompetencyType`,
  `ProficiencyLevel`, `KeyBehavior`, `DevelopmentProgram`, `DevelopmentProgramGrade`,
  `ReviewTool`, `Training`, `CompetencyImplementation`.
- **What the split fixed**
  - `proficiency_level_id` meant *two* things — the owning level on `key_behavior` rows, a
    *selected* level everywhere else. Now `key_behaviors.proficiency_level_id` (ownership,
    a real FK) vs the `competency_proficiency_level` pivot (selection).
  - Hand-synced mirror columns are gone: `value`↔`value_en`, `grade`↔`grades[0]`,
    `key_behavior_id`↔`key_behavior_ids[0]`, `proficiency_level_id`↔`proficiency_level_ids[0]`.
    **`name_en` is now the single canonical name** (`name_id` is the optional Indonesian
    display name). Note the legacy data had `value_en` blank on 400/409 rows, so the backfill
    is `name_en = COALESCE(NULLIF(value_en,''), value)`.
  - `related_program` (a json list of id *strings*) is now the
    `competency_development_program` pivot. `syncProgramCompetencies()` used to load every
    competency into PHP and diff arrays; it is a `sync()` call now.
  - `grades` json → `development_program_grades` rows.
- **`App\Enums\MasterDataType`** (backed enum) replaces the `type` string. It maps a kind to
  its model/table, whether it has a description, and which
  `individual_development_plans` column stores its name verbatim (rename cascades + delete
  guards key off that). It is still the wire value the settings screens post as `type`.
- **`App\Services\IdpMasterService`** holds the master writes (create/update/deletionBlocker/
  delete); `IdpSettingController` is back to validation + prop shaping.
- **Wire contract mostly unchanged** — props are still `{id, value, value_en, value_id, …}`
  (`value` and `value_en` both come from `name_en`). Three deliberate breaks:
  `/idp-setting/masters/{id}` → **`/idp-setting/masters/{type}/{id}`** for PUT/DELETE (ids are
  only unique within a kind now); an implementation's `competency_name_id` → `competency_id`;
  and a competency exposes `proficiency_level_ids` rather than the first-of-list mirror.
- **Migrations** are a reversible trio: `..._create_idp_master_tables`,
  `..._migrate_development_plan_masters_data` (copies rows **keeping their ids**, so every
  existing reference stays valid with no remapping), `..._drop_development_plan_masters_table`.
- `development_programs.name_en` is **TEXT**, not a string: program names are activity
  descriptions up to ~353 chars. Its validation cap is 1000 (255 for every other master, as
  before); uniqueness stays in validation since TEXT needs a prefix index.
- ⚠️ Not verified: SQLite (no `pdo_sqlite` in the local PHP), so the migrations were only run
  against MySQL. The one MySQL-specific statement (the `name_en(191)` prefix index) is guarded
  by a driver check.

## Phase 5.4 — Active/inactive on the dated IDP masters ✅ DONE (code + runtime-verified)
**Supersedes the earlier effective-period design.** Competencies, proficiency levels and
review tools carried an `effective_start_date` / `effective_end_date` window; the client only
ever needs to say whether a master applies *at all*, and wants the change attributed — which a
date window cannot record. Both columns are gone, replaced by `is_active`.

- **Migration** `..._replace_effective_dates_with_is_active` (reversible, round-trip verified):
  adds `is_active` (boolean, default true) to `competencies`, `proficiency_levels`,
  `review_tools`, backfills it from the old window (effective today ⇒ active), then drops the
  two date columns. `down()` cannot recover the original dates — it restores the *meaning*:
  an active row gets an open window, an inactive one a window that closed yesterday.
- **`App\Models\Concerns\HasActiveState`** (replaces `HasEffectivePeriod`) — the
  `active()` / `inactive()` scopes + `isActive()`. `MasterDataType::hasActiveState()` says
  which kinds carry the flag. Each of the three models also declares
  `protected $attributes = ['is_active' => true]`: without it a row created without the field
  relies on the column default and the in-memory model has **no** `is_active` at all, which
  made `isActive()` read false and the toggle's "already in that state" guard misfire.
- **Audit trail, deliberately outside the database** — `App\Services\MasterStatusAudit`
  appends one JSON line per transition to `storage/app/audit/master-status-YYYY-MM.jsonl`
  (`at`, `type`, `id`, `name`, `active`, `by{id,employee_id,name}`). Toggling is a frequent,
  low-value write, so it never touches the DB. Entries are self-contained — the master's name
  and the actor's name as they were at the time — so history survives a rename or a deletion.
  A write failure is logged and never fails the save. `for($type, $id)` reads it back, walking
  month files newest-first. Only **transitions** are recorded, so re-saving an unchanged form
  adds nothing.
- **Endpoints**: `PUT /idp-setting/masters/{type}/{id}/active` (toggle, via
  `IdpMasterService::setActive`) and `GET …/status-history` (JSON, read from the log).
  The edit drawer's switch and the list toggle both funnel through the service, so either
  route is audited.
- **Where it bites** — every cross-master check that used to compare date windows is now a
  flag test, and each keeps the same **exemption**: what a row already stores is never
  rejected, so deactivating a master can't make an unrelated edit impossible.
  - `IdpService` picker narrowing: `->effective()` → `->active()` (competency names + review
    tools). Plans store the name verbatim, so existing items still display.
  - Competency form: only active levels may be pinned (`assertLevelsActiveForCompetency` →
    `assertLevelsActive`).
  - Master Implementation: an inactive competency can't be mapped and inactive levels can't be
    pinned (`assertImplementationActive`).
  - Master Training: same two rules (`assertTrainingSelectionsUsable` /
    `assertTrainingLevelsUsable`, which now delegates to `assertLevelsActive`).
  - Development program: `assertCompetenciesUnexpired` → `assertCompetenciesActive`.
- **Wire contract**: `IdpSettingController::option()` ships `is_active` (instead of the two
  dates) for the masters that have the column; every other payload is unchanged.
- **Vue**: `Components/Domain/ActiveStateField.vue` (the switch in a form),
  `ActiveStateCell.vue` (Active/Inactive badge that doubles as the toggle + a history button)
  and `MasterStatusHistory.vue` (drawer that fetches the trail on open). The
  `useEffectivePeriod.ts` composable and the two `EffectivePeriod*` components are deleted.
  Locale keys `status` / `activeLabel` / `activeHint` / `inactiveBadge` / `activate` /
  `deactivate` / `statusHistory` / `noStatusHistory` / `changedBy` / `activatedBadge` /
  `deactivatedBadge` / `loading` in `en`/`id`, plus the renamed per-screen messages
  (`levelActiveScopeHint`, `levelInactive`, `noActiveProficiencyLevels`,
  `competencyInactiveForImplementation`, `inactiveLevelsPinned`,
  `competencyInactiveForTraining`, `proficiencyInactiveForTraining`,
  `noActiveProficiencyLevelsForType`, `noActiveProficiencyForCompetency`).
- ⚠️ The audit directory is created on demand and is gitignored (`storage/app/.gitignore`), so
  it is **not** backed up by anything that only backs up the database — worth knowing before
  the client treats it as a compliance record.

## Phase 5.5 — Development program scoped by Master Implementation ✅ DONE (code + runtime-verified)
The development-program form on `/idp-setting` no longer invents its own scope: it reads
the Master Implementation map. Implementation is what says at which proficiency levels a
competency is actually rolled out and to which grades, so a program can only target what
has been implemented.

- **One competency per program.** The multi-select is gone: the form picks a single
  competency (`SearchableSelect`). The link stays the `competency_development_program`
  pivot — a competency reaches many programs, and the Competency screen edits that side —
  so `related_competencies` is still posted as a list, now capped at `max:1`. Three legacy
  programs (ids 295, 314, 537) carry two competencies; the drawer opens on the first and
  saving settles the link on it.
- **Competencies** are filtered by the active flag (Phase 5.4): an inactive competency is
  off the list.
- **Proficiency levels** come from `competency_implementations` for the picked competency —
  not from the competency's own `proficiency_level_ids` as before — and are labelled
  `PL1 (Grade Level 2C-2D)`. The grade suffix collapses runs that sit next to each other in
  the corporate grade order into a range (`gradeRangeLabel`).
- **Grades** are *only* the grades that implementation covers for the *chosen* level —
  never the full corporate list. No level chosen, or nothing implementing it, means no
  grade options at all (the field explains which). An implementation with no grades of its
  own covers every grade, same convention as the implementation screen.
- **Wire contract**: `IdpSettingController@index` gains an `implementations` prop
  (`{competency_id, proficiency_level_ids, grades}` per row, via `implementationScopes()`).
- **Server mirror**: `assertProgramSelectionsUsable()` rejects inactive competencies, a level
  no implementation maps for the chosen competencies, and grades outside that mapping.
  Only what the form *adds* is checked — whatever the program already stores is exempt, so
  editing an unrelated field never fails because a master has since been deactivated or an
  implementation has since narrowed. "Others"-type programs skip all of it (they free-type).
  The Vue side keeps the loaded competencies/level/grades on offer for the same reason.
- New locale keys `gradeLevel` / `noImplementedProficiency` / `proficiencyFromImplementation`
  / `gradeFromImplementation` / `pickProficiencyFirst` / `noGradesForProficiency` in `en`/`id`.
- ⚠️ **Operational note**: `competency_implementations` is empty in the current local DB, so
  the level dropdown will be empty for every program until Master Implementation rows exist.
  Programs saved before this change keep (and can re-save) the level they already store.

## Phase 5.6 — Development program named from Master Training ✅ DONE (code + runtime-verified)
A development program's name is now either typed (bilingual, as before) or taken from the
Master Training catalogue. The drawer carries a two-way switch — **Program name** shows the
EN/ID inputs, **Master training** hides them and shows a training picker.

- **`development_programs.training_id`** (nullable FK → `trainings`, `nullOnDelete`;
  `..._add_training_id_to_development_programs_table`, reversible, round-trip verified) is
  provenance only. The name still lives on `name_en` / `name_id`, **copied from the training
  during validation** — IDP rows name a program verbatim, uniqueness is per development
  model, and every list reads those columns, so there is still exactly one place the name
  is read from.
- **`IdpSettingController::applyTrainingName()`** merges the training's names onto the
  request *before* the rules run, so a training-sourced name is policed by the same
  `required` / `max` / unique-per-model rules as a typed one. The copy happens on every
  save, so re-saving a program picks up a training that has since been renamed (and
  `cascadeRename` carries that on to the IDP rows). There is deliberately **no** automatic
  cascade from a training rename to its programs — the mirror refreshes on the program's
  next save.
- **Delete guard**: a training that names a development program can no longer be deleted
  (`IdpMasterService::deletionBlocker`), matching how every other referenced master behaves.
- **Wire contract**: `index()` gains a `trainings` option list, and each program payload
  carries `training_id`. The form posts `training_id` alongside `value_en` / `value_id`;
  the server is the authority on the name whenever a training is set.
- **Vue**: `nameSource` (`'program' | 'training'`) seeded from the program's `training_id`,
  `showNameInputs`, and a `typedName` stash so flipping the switch never loses what was
  written. New locale keys `nameSource` / `nameSourceProgram` / `nameSourceTraining` /
  `nameFromTrainingHint` / `noTrainings` in `en`/`id`.
- ⚠️ **Operational note**: `trainings` is empty in the current local DB, so the picker shows
  its empty state until trainings are added on the Master Training page.

## Phase 5.7 — Master Training scoped to a competency + org location ✅ DONE (code + runtime-verified)
A master training was only a bilingual name + description. It now records **what it
develops** and **who it is for**, so the catalogue can be filtered rather than read end to
end. Five nullable columns on `trainings`
(`..._add_scope_columns_to_trainings_table`, reversible): `competency_type_id`,
`competency_id`, `proficiency_level_id` (all FKs, `restrictOnDelete`) plus the raw kpncorp
strings `business_unit` and `work_location`. The proficiency level and the two corporate
scope columns were then all **split off into their own tables** — see below; only
`competency_type_id` and `competency_id` remain on `trainings`.

- **Cascade + active flag**, mirroring Master Implementation: competency type → competency
  (of that type, inactive ones off the list) → proficiency level. An **inactive** master
  can't be picked. What the training already stores is exempt, so editing an unrelated field
  never fails because a master has since been switched off
  (`IdpSettingController::assertTrainingSelectionsUsable`, mirrored in the Vue by
  `inactive()`).
- **Proficiency levels are many, not one.** `trainings.proficiency_level_id` became the
  `proficiency_level_training` pivot (`..._create_proficiency_level_training_table`,
  reversible — it copies the existing single values into the pivot on the way up and back
  into the column on the way down, keeping the lowest level id since a column can only hold
  one; round-tripped with data). Same shape as
  `implementation_proficiency_level`. The form uses `MultiSelect` with **select all**, and
  the wire field is `proficiency_level_ids` (a list) — `IdpMasterService::syncLinks()` syncs
  it, the same way a competency's levels are synced. An empty list is allowed: the training
  is simply not pinned to a level.
- **Levels are scoped by the competency type** (a level filed under another type is off the
  list; an untyped level is global and fits every type — the same rule the competency form
  uses), and separately by their **own** active flag. They are *not* narrowed through the
  competency, unlike the competency and implementation forms: a training targets levels
  directly. The two checks differ on exemption (`assertTrainingLevelsUsable`): the type check
  applies to every submitted level with none, because a level's type is stable and a mismatch
  means the pick has to be redone; the period check exempts levels the training already
  stores, since a level lapsing must not make an unrelated edit impossible. The form keeps
  such a level listed and flags it amber.
- **Business units and work locations are many, not one.** The two columns became the child
  tables `training_business_units` / `training_work_locations`
  (`..._create_training_scope_tables`, reversible — values copied in on the way up and back
  on the way down, keeping the first alphabetically; round-tripped with data). They hold raw
  kpncorp strings, the same shape `development_program_grades` / `implementation_grades`
  already have for grades, with models `TrainingBusinessUnit` / `TrainingWorkLocation` and a
  `replaceValues()` wholesale replace in `IdpMasterService::syncLinks()`. Wire fields
  `business_units` / `work_locations`.
- **Work location** is `kpncorp.locations.area`, grouped by `locations.company_name` (the
  same grouping the employee master calls `group_company`), so the picker cascades from the
  chosen business units — the **union** of their sites, since a training offered in several
  units may run at a site of any of them. `IdpSettingController::workLocationData()` reads it
  defensively — an unreachable kpncorp leaves the screen working with empty options. The
  business-unit list is itself the **union** of `locations.company_name` and
  `employees.group_company`, so a unit present in only one source still appears (its location
  list is then simply empty). The two do not fully agree in the live data — `Plantations` vs
  `KPN Plantations` — hence the union rather than one source.
- **Server mirror**: the competency must belong to the chosen type, no picked master may be
  inactive, `business_units` is `required_with:work_locations`, and every location must belong
  to one of the chosen units — skipped when none of them have known locations, so a gap in
  `locations` never blocks a save. Locations the training already stores are exempt, so
  dropping a unit doesn't make the row uneditable (the form drops the orphaned locations
  instead).
- **Delete guards**: a competency type / competency / proficiency level assigned to a
  training can no longer be deleted (`IdpMasterService::deletionBlocker`), same as every other
  referenced master.
- **Wire contract**: `masterTraining()` gains `competencyTypes` / `competencies` /
  `proficiencyLevels` / `businessUnits` / `workLocationsByBu`, and each training payload
  carries the five new fields. The form posts them through the **unchanged** shared
  `/idp-setting/masters` endpoints with `type=training`.
- **Vue**: `Pages/Idp/MasterTraining.vue` — a "Scope" block (type / competency / level, with
  an amber flag on stored-but-inactive picks) and
  an "Organization Scope" block (business units → work locations); the table gained
  Competency (with its type beneath, sortable) plus Proficiency Level, Business Unit and Work
  Location chip columns, all searchable. Every list field is a `MultiSelect` with **select
  all** / **clear all**. New locale keys `workLocation` / `workLocationPickHint` /
  `noEffectiveProficiencyLevels` / `competencyExpiredForTraining` /
  `proficiencyExpiredForTraining` / `businessUnitsPickHint` / `workLocationsPickHint` /
  `selectAll|clearAll` × `BusinessUnits|WorkLocations` / `noneForBusinessUnits` in `en`/`id`
  (the level's empty state reuses the existing `pickTypeFirst` /
  `noProficiencyLevelsForType`, and the level select-all reuses `selectAllLevels` /
  `clearAllLevels`).
- ⚠️ **Operational note**: the scope is optional except the competency type + competency,
  which are **required** (matching Master Implementation). `trainings` was empty when this
  landed, so nothing needed backfilling.

## Phase 5.8 — Master Implementation: active/inactive + multi business unit ✅ DONE (code + runtime-verified)
- **`competency_implementations.is_active`** (boolean, default true) — a mapping can be
  switched off without deleting it, using the same `HasActiveState` trait and
  `$attributes` default as the IDP masters. Endpoints
  `PUT /idp-setting/implementations/{implementation}/active` and `…/status-history`.
- **Audit trail** — `MasterStatusAudit` is no longer keyed on `MasterDataType`: `record()` /
  `for()` take a plain **subject string**, so implementations log under
  `MasterStatusAudit::IMPLEMENTATION` alongside the masters' wire values, in the same
  `storage/app/audit/master-status-YYYY-MM.jsonl` file. A mapping has no name of its own, so
  the entry is labelled with the competency it maps. Only transitions are logged, as before.
  `MasterStatusHistory.vue` now takes a **`url`** rather than a master type, which is what
  lets both subjects share it.
- **Business unit is many, not one.** `competency_implementations.business_unit` became the
  child table `implementation_business_units` (`..._add_active_and_business_units_to_implementations`,
  reversible — the value is copied into the child table on the way up and back into the column
  on the way down, keeping the first alphabetically; round-tripped with data). Same shape as
  `implementation_grades` / `training_business_units`, with model `ImplementationBusinessUnit`.
  Wire field `business_units`; the form uses `MultiSelect` with **select all**.
- **Duplicate guard reworked.** It used to key on a single `business_unit`. Two rows that
  differ only by which units they cover are genuinely different mappings, so the guard now
  compares the **sorted unit sets** (competency + job family + function + position + the same
  set). Verified: identical set rejected regardless of order; a subset, a disjoint set, an
  empty set, and re-saving the row itself all accepted.
- **Hierarchy children are the union across the selected units** (`unionFor()`), matching how
  Master Training unions the work locations of several units. Note `job_family` /
  `function_name` / `position` are still stored and submitted but **not rendered** — the
  org-scope section only offers the business units — so those option lists are currently
  unused; they were updated rather than deleted so the columns stay coherent.
- **Fixed in passing**: `openImpl()` assigned the business unit and then the hierarchy
  children in one synchronous block, but the cascade watcher flushes *after* it and cleared
  the children it had just restored. A `loadingForm` guard (released on `nextTick`) suppresses
  the cascade while a row is being loaded. Invisible before only because those fields are not
  rendered.
- **Vue**: `Pages/Idp/MasterImplementation.vue` gains a Status column (Active/Inactive badge
  that toggles, plus a history button), a business-unit chip column, the `ActiveStateField`
  switch in the drawer, and the shared history drawer. Search covers the unit list. All the
  locale keys it needs (`businessUnitsPickHint`, `selectAllBusinessUnits`,
  `clearAllBusinessUnits`, `status`, …) already existed from Master Training and Phase 5.4.
- ⚠️ `businessUnitPickHint` (the old singular "Select a business unit") is now unused by this
  screen; it is still referenced elsewhere, so it was left in place.

## Phase 5.9 — Master Training: active/inactive ✅ DONE (code + runtime-verified)
Trainings are a `MasterDataType`, so this rode almost entirely on the machinery Phase 5.4
already built: flipping `MasterDataType::Training->hasActiveState()` to true is what wires up
the write (`IdpMasterService::attributes()`), the payload (`option()` ships `is_active`), the
validation rule, the toggle endpoint and the audit trail — all shared, none of them touched.

- **Migration** `..._add_is_active_to_trainings_table` (reversible): `is_active` boolean,
  default true, on `trainings`. Nothing to backfill — the table is empty locally, and a
  pre-existing row would default to active.
- **`Training`** gains `HasActiveState`, the `is_active` fillable/cast, and the same
  `protected $attributes = ['is_active' => true]` default as the other three masters.
- **Endpoints** are the existing shared ones: `PUT /idp-setting/masters/training/{id}/active`
  and `GET …/status-history`, logging under the `training` subject in the same
  `storage/app/audit/master-status-YYYY-MM.jsonl`.
- **Where it bites**: the development-program form's **name source** picker
  (`Settings.vue`) now offers only active trainings. A program already named from a training
  keeps that training on offer (`loadedTrainingId`), so editing an unrelated field never
  blanks the name source. Nothing else changes — the program stores a *copy* of the name
  (Phase 5.6), so an inactive training never breaks an existing program, and the delete guard
  still blocks removing a training a program was named from.
- **Vue**: `Pages/Idp/MasterTraining.vue` gains a Status column (Active/Inactive badge that
  toggles + a history button), the `ActiveStateField` switch as the last field in the drawer,
  and the shared history drawer. Every locale key it needs already existed.
- Verified: created active by default; create-active logs nothing; two toggles log exactly two
  entries newest-first with the actor; a repeat toggle to the same state is a no-op; re-saving
  the form unchanged adds nothing; `option()` payload carries `is_active`; `active()` /
  `inactive()` scopes split correctly.

## Entity map (facecard → kpn-tmp)
App-owned: CompetencyAssessment, DevelopmentModel, Competency, CompetencyType,
ProficiencyLevel, KeyBehavior, DevelopmentProgram, ReviewTool, Training,
CompetencyImplementation, IndividualDevelopmentPlan, ResultSummary, MatrixGradeConfig,
JobStatus, PerformanceAppraisal, ImportLog, UserGuide, ImplementationBusinessUnit,
User + Spatie Role/Permission.
`kpncorp` (read-only): Employee, FormalEducation, WorkExperience, TrainingCertification,
MovementTransaction, PromotionTransaction.
