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
- **Never hard-code a URL in the UI.** Every path comes from a route name via
  `route()` in `resources/js/Config/route.ts` (a thin Ziggy wrapper —
  `@routes` in `app.blade.php` publishes the real route table, so renaming a path
  in `routes/web/*.php` updates every caller). The wrapper's only addition is
  `absolute: false`: the sidebar compares hrefs against Inertia's `page.url`, a
  bare path, so an absolute URL would silently stop menu items highlighting.
- Routes are split by domain under `routes/web/` (shell, facecard, idp, talent,
  master-data, admin), each loaded inside the single `auth` group in `web.php`.

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
EN/ID inputs, **Master training** hides them and shows a training picker. ⚠️ **The switch was
retired by Phase 5.23**: which of the two applies is now read off the development model's
`uses_master_training` flag. Everything else here (the `training_id` provenance column, the
name copied during validation, the delete guard) still stands.

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

## Phase 5.10 — IDP plan form: the master-driven cascade ✅ DONE (code + runtime-verified)
The add/edit plan drawer used to offer a hard-coded `Soft Competency / Technical Competency`
pair, every competency, and every development program in the catalogue. All three pickers now
narrow off the IDP master data, and the server mirrors each narrowing.

- **Competency type** comes from the `competency_types` master (not a fixed pair). A plan
  stores the type's NAME verbatim, so the option value is `name_en` like every other master
  here. The catch-all "Others" type holds no competencies, so a plan on it **free-types** the
  competency name — the same rule development programs follow.
- **Competency name** is narrowed to the chosen type, and to **active** competencies only.
  A competency is always filed under a type, so the type scopes it **strictly** (an untyped
  competency is legacy data and belongs under no type at all).
- **Development program** is narrowed by all three of the plan's own choices:
  1. the **development model** the plan is filed under (`development_programs.development_model_id`);
  2. the **competency** it builds (the `competency_development_program` pivot); and
  3. the **competency type**.
  The type and model scope programs **loosely** — a program with no type or no model is legacy
  data and counts as global — because they are narrowed primarily by the competency, and
  treating a missing value as "none" would empty the picker. A competency with **no** linked
  programs falls back to the whole catalogue, still narrowed to the model and the type.
- **Wire contract**: every program option carries `model_id` (in the flat
  `options.developmentPrograms` list and in each `competencyMap` entry). The flat list is
  deduped **per model**, not globally — a program name is unique *within* a development model,
  so the same name may legitimately exist under two models as two different programs. In the
  current data every one of the 36 linked competencies has programs spread across all three
  models, so the model filter is what stops a 70-model plan from offering a 10-model program.
- **Server mirror** (`StoreIndividualDevelopmentPlanRequest::withValidator`): the type must be
  one of the masters; competency and review tool must be active; the competency must belong to
  the type; the program must fit the model, the competency and the type. Every check **exempts
  the value the plan already stores**, so deactivating a master or re-filing a program can
  never make an unrelated edit impossible, and a name matching no master at all is left alone
  (legacy plans hold free text).
- **Vue** (`Components/Domain/IdpPanel.vue`): `matchesType` / `fitsType` / `fitsModel` mirror
  the server rules; a dependent field stays **locked with an explanation** rather than showing
  an empty dropdown; changing a parent choice drops a child that no longer fits (suppressed by
  `loadingForm` while a row is being loaded, so editing an unrelated field never blanks what
  the row stores); stored-but-off-list values stay selectable and are flagged amber, with
  "deactivated" and "filed under another type" told apart. New locale key `noProgramsForModel`
  ("this competency has programs, but none under this development model") separates that from
  `noProgramsForCompetency`.

## Phase 5.11 — Development program: "Others" picks a real competency ✅ DONE (code + runtime-verified)
A development program on the catch-all **Others** competency type used to free-type the
competencies it develops into a textarea (`development_programs.custom_competency`). It now
picks a competency master filed under Others, exactly like a program on any other type — the
competency picker no longer special-cases the type at all.

- **Migration** `..._drop_custom_competency_from_development_programs_table` (reversible):
  drops `custom_competency`. Nothing to migrate — all 357 live programs had it null/empty,
  and every one already carries a competency link.
- **The free-typed proficiency level stays.** `custom_proficiency_level` is untouched: Others
  has no Master Implementation map to draw a level from, so that field is still the only way
  to record one. `programAttributes()` keeps the "level or free text, never both" rule.
- **`syncLinks()`** no longer blanks a program's competency pivot on Others — it syncs
  `related_competencies` for every type.
- **`assertProgramSelectionsUsable()`** lost its Others early-return, so an Others program
  gets the same checks as any other: the competency must be active, and the level + grades
  must come from an implementation. Since Others submits no `proficiency_level_id`, only the
  competency check actually bites. The existing exemption for what the program already stores
  is unchanged.
- **Wire contract**: the program payload and the master validation rules drop
  `custom_competency`. `related_competencies` stays `nullable|array|max:1` (unchanged — the
  server has never required it for any type).
- **Vue** `Pages/Idp/Settings.vue`: one `SearchableSelect` for the competency, scoped by type
  and to active masters, with `*` always shown; the Others textarea, the `customCompetency`
  snapshot/row/search plumbing, and the violet free-text chip in the program table are gone.
  The competency-type watcher now restores the cached competency for every type and only
  swaps the *proficiency* field on Others. Locale: `othersTypeHint` rewritten;
  `customCompetencyPlaceholder` / `customCompetencyHint` deleted (`othersType`, used only as
  the removed chip's tooltip, is now unused but left in place).
- ⚠️ **Operational note**: the `Others` competency type currently has **zero** competencies,
  so the picker shows its `noCompetenciesForType` empty state until competencies are filed
  under it on the Competency master page.

**Form declutter (same slice).** The program drawer had grown a grey explanatory line under
almost every field plus a dashed "Summary" card restating the whole form. Both are gone:
every per-field and per-section hint (`programTypeHint`, `othersTypeHint`,
`proficiencyFromImplementation`, `gradeFromImplementation`, `modelPickHint`,
`programScopeHint` / `programPlacementHint` / `programIdentityHint`, the two
`nameSource*Hint` card blurbs and `nameFromTrainingHint`) and the summary block with its
`summaryRows` / `selectedCompetencyName` / `selectedProficiencyName` computeds. The 15
resulting dead keys were deleted from the `idp.settings` block in `en`/`id` — note
`programScopeHint` and `othersTypeHint` also exist under `idp.form`, where `IdpPanel` still
uses them, so only the settings copies went. **Kept** on purpose: the step badges and section
icons, the required `*` / `(optional)` markers, the validation errors, and the dashed
locked/empty-state boxes (`pickTypeFirst`, `noCompetenciesForType`,
`noImplementedProficiency`, `pickProficiencyFirst`, `noGradesForProficiency`, `noTrainings`)
— those explain why a field has no options, which nothing else says. The name-source radio
cards became single-line, so they centre their contents now.

## Phase 5.12 — IDP plan form: "Others" picks a master, competencies scoped by model ✅ DONE (code + runtime-verified)
Two changes to the add/edit plan drawer, both narrowing what the pickers offer:

- **The catch-all "Others" type picks a master competency**, like every other type. The
  free-typed competency name is gone — the same move Phase 5.11 made on the development
  program form. `is_others` is no longer shipped in `options.competencyTypes`
  (`IdpService`), and the request's `isOthers()` early-return is gone, so Others now gets
  the full set of cross-master checks: the competency must be active, must belong to
  Others, and the program must fit. `CompetencyType::isOthers()` itself stays —
  `IdpMasterService` / `Settings.vue` still use it for the free-typed proficiency level.
- **Competency names are narrowed by the development model too**, not just the type. A
  competency reaches its programs through the `competency_development_program` pivot, and
  a plan is filed under exactly one development model, so a competency whose linked
  programs all sit under *other* models is off the list — there would be nothing to pick
  in the program field below it. A competency with **no** links at all stays on offer
  (it is global, and the program picker then falls back to the whole catalogue — the
  existing convention, unchanged).
- **Development program options are unchanged** — already narrowed by model + competency +
  type since Phase 5.10. The three predicates are now one shared `selectable()` on both
  sides, so "the competency is offered" and "the competency has a selectable program" can
  never disagree.
- **Server mirror**: a new `competency_name` check in
  `StoreIndividualDevelopmentPlanRequest::withValidator` rejects a competency with no
  program under the chosen model, with the usual **exemption** for the value the plan
  already stores — so an unrelated edit to an existing plan never fails because the
  master data has since been re-filed. A name matching no master at all is still left
  alone (legacy plans hold free text).
- **Vue** (`IdpPanel.vue`): the Others `<input>`, its "Free text" badge and the
  `othersTypeHint` note are gone; the competency field is always a `SearchableSelect`.
  New empty state `noCompetenciesForModel` ("this type has competencies, but none with a
  program under this model") separates that from `noCompetenciesForType`, and a stored
  off-list competency is now flagged with one of **three** reasons — deactivated
  (`inactiveMaster`), filed under another type (`typeMismatch`), or no program under this
  model (`modelMismatch`, new). The competency-dropping watcher also keys on
  `development_model_id`. Dead keys `freeText` / `competencyNamePlaceholder` /
  `othersTypeHint` deleted from `idp.form` in `en`/`id`.
- ⚠️ **Not touched**: `SingleEmployeeDevelopmentPlanImport` (the IDP Excel import) still
  hard-codes `Soft Competency` / `Technical Competency` and only checks the master link
  for Soft Competency. It does not follow this cascade — a separate slice.
- ⚠️ **Operational note**: in the current local data 35 of 36 competencies are untyped and
  all 357 programs are untyped, so only `Soft Competency` offers anything (one competency)
  and `Others` / `Technical Competency` show their empty state until competencies are filed
  under them on the Competency master page.

## Phase 5.13 — IDP plan form: competency type scoped by the development model ✅ DONE (code + runtime-verified)
The last field in the add/edit plan cascade that ignored the development model. Competency
names and development programs were already narrowed by it (Phases 5.10 / 5.12), but the
**competency type** offered every master — so a type could be picked that dead-ended on the
very next field. It is now narrowed off the same master-development data.

- **The rule**: a competency type is offered only when it holds an **active** competency that
  reaches a **development program filed under the plan's development model** — i.e. exactly
  when the competency picker below it would have something to show. A competency with no
  linked programs at all stays global (the program picker then falls back to that model's
  whole catalogue), so such a type is always offered; that is what stops the narrowing from
  emptying the picker on legacy data.
- **Vue** (`Components/Domain/IdpPanel.vue`): the type/model predicates (`matchesTypeName`,
  `fitsTypeName`, `selectableFor`, `reachesModelFor`) now take the competency type as an
  argument rather than reading `form.competency_type` — the type picker has to ask "would THIS
  type offer anything?", which cannot be answered against the type currently selected. The
  form-reading wrappers (`matchesType` / `fitsType` / `selectable` / `reachesModel`) are kept,
  so every other caller is unchanged. New `typeUsable()` / `usableCompetencyTypes`, an empty
  state (`noTypesForModel`) when no type works under this model, an amber flag
  (`typeModelMismatch`, vs `inactiveMaster` when the master is gone) on a stored off-list
  type, and a watcher that drops a type that no longer fits when the model changes —
  suppressed by `loadingForm`, so opening an existing plan never blanks what it stores.
- **Server mirror** (`StoreIndividualDevelopmentPlanRequest::withValidator`): the shared
  closures moved above the early returns so the new check can reuse them, plus a
  `$reachesModel` closure. The check runs right after "the type must be one of the masters"
  and returns early, so an unusable type reports one clear error instead of cascading. It
  **exempts the type the plan already stores**, like every other check here.
- **Wire contract unchanged** — the front end derives this from `options.competencyNames`
  (which already carry `competency_type`) and `competencyMap` (which already carry
  `model_id`). `options.competencyTypes` still ships every type, which is what keeps a stored
  off-list type labelled.
- Verified against the local DB: a usable type + real program passes; the two types with no
  competencies are rejected; a bogus type still reports "not one of the configured types";
  editing a plan that stores an unusable type passes, while switching it to one is rejected;
  and a competency linked only to model 9's programs makes its type usable under model 9 but
  not model 8 (checked in a rolled-back transaction).
- ⚠️ **Operational note**: with the current data (35 of 36 competencies untyped, all 357
  programs untyped) only `Soft Competency` has a competency, so the type picker now offers
  **just that one** until competencies are filed under `Technical Competency` / `Others` on
  the Competency master page. `noCompetenciesForType` on the competency field is consequently
  near-unreachable now — it only shows for a stored off-list type — but is kept.

**Form declutter (same slice).** The plan drawer carried a grey explanatory line under
several fields plus a one-line blurb in every section header. Both are gone, the same trim
Phase 5.11 gave the development-program drawer: the four `FormSection` `:hint`s
(`sectionAreaHint` / `sectionProgramHint` / `sectionTimelineHint` / `sectionResultHint`), the
per-field notes (`reviewToolsHint`, `programScopeHint` / `programScopeAllHint`, `outcomeHint`,
`evidenceRequiredHint`) and the green "ready to submit" banner (with its `readyToSubmit`
computed). The 10 dead keys were deleted from the `idp.form` block in `en`/`id` — note
`idp.settings.reviewToolsHint` is a different key, still used by the Review Tools page. The
expected-outcome character counter keeps its right edge with `ml-auto` now that the hint no
longer occupies the left half of its row. **Kept** on purpose: the step badges and section
icons, the required `*` / `(optional)` markers, the option counters, the error summary and
per-field errors, the amber off-list flags, the dashed locked/empty-state boxes
(`pickTypeFirst`, `pickCompetencyFirst`, `noTypesForModel`, `noCompetencies*`, `noPrograms*`,
`evidenceLocked`), the full-text read-back of the selected program, the duration chip, and
the footer's "still needed" list — each says something no other element does.

## Phase 5.14 — Master Data menu: competency type split from competency ✅ DONE (code + runtime-verified)
`/idp-setting/competency` was one screen carrying two entities: a competency-type table on
top and the competency table below, both driven by one `masterType` ref switching a shared
drawer. They are two screens now, on a new **Master Data** menu that sits *before* IDP
Settings in the Administration section.

- **Pages**: `Pages/MasterData/CompetencyType.vue` (types table + bilingual name/description
  drawer; search added, since a standalone screen needs it) and
  `Pages/MasterData/Competency.vue` (moved from `Pages/Idp/Competency.vue`, git-tracked as a
  rename). The competency page drops the types table and the `masterType` switch — the type
  is a `const MASTER_TYPE = 'competency_name'`, so `openMaster` / `deleteMaster` lost their
  type argument and `masterHasDescription` (always true for both kinds) is gone.
- **Competency types are still read on the competency page**, just read-only: they name the
  type column, drive the type filter, and scope the proficiency levels the form may pin. So
  `competencyTypes` still ships with the competency props — but it left `reloadOnly`
  (`['competencies', 'flash']`), since nothing on that screen can change a type any more.
- **Routes**: `GET /master-data/competency-type` (`master_data.competency_type`) and
  `GET /master-data/competency` (`master_data.competency`), both still gated
  `permission:view_idp_master`; `/idp-setting/competency` is gone. The **write** endpoints are
  unchanged — both screens post through the shared `/idp-setting/masters` (+ `/{type}/{id}`,
  `/active`, `/status-history`) with `type=competency_type` / `type=competency_name`, so the
  wire contract, validation, delete guards and audit trail are untouched.
- **Controller**: `IdpSettingController::competency()` split into `competencyType()` (types
  only) and `competency()` (unchanged payload, new component name). Kept in that controller
  rather than a new one so the private `option()` / `competencyTypesData()` helpers stay
  shared.
- **Nav**: new `masterData` parent (`fa-solid fa-database`) with children
  `masterDataCompetencyType` / `masterDataCompetency`, inserted before `idpSetting`, whose
  `idpSettingCompetency` child was removed. Note the sidebar's prefix-matching `isActive`
  does not confuse `/master-data/competency` with `/master-data/competency-type` (it only
  matches on an exact hit or a trailing `/`).
- **Locale**: nav keys `masterData` / `masterDataCompetencyType` / `masterDataCompetency`;
  page keys `competencyTypeTitle` / `competencyTypeSubtitle` / `searchCompetencyType`;
  `competencySubtitle` reworded (it no longer covers types); `idpSettingCompetency` deleted.
- **Later move**: **Master Implementation** joined this menu as its third item, so all three
  screens that manage the shared competency masters sit together. Its page moved to
  `Pages/MasterData/MasterImplementation.vue` (git-tracked rename) and its route to
  `GET /master-data/master-implementation` (`master_data.master_implementation`); the write
  endpoints stayed at `/idp-setting/implementations/*`, untouched, like the competency
  screens' shared writes. The two competency menu labels gained a `Master` prefix
  (`Master Competency Type` / `Master Competency`), and `idpSettingMasterImplementation` was
  replaced by `masterDataMasterImplementation`. Page titles were not renamed.

## Phase 5.15 — Corporate business-unit master + a business unit on competency type ✅ DONE (code + runtime-verified)
Every business-unit dropdown in the app used to derive its options from whatever distinct
values happened to sit in `employees.group_company` (plus `locations.company_name` on Master
Training). So a unit with no employees yet was invisible, and the two sources disagreed
("Plantations" vs "KPN Plantations", which showed up as two units). There is now one source
of truth, and competency types carry a business-unit scope of their own.

- **`App\Models\BusinessUnit`** — read-only, on the **kpncorp** connection, table
  `master_bisnisunits` (`kode_bisnis` PK / `nama_bisnis` / `approval_medical`, 7 rows:
  Plantations, Property, Cement, Katingan, KPN Corporation, Downstream, Others). Non-incrementing
  string key, no timestamps, `$guarded = ['*']`.
  - **`names()`** — the unit names in the master's own order (by `kode_bisnis`, which keeps the
    catch-all "Others" last instead of sorting it into the middle). Guarded: an unreachable
    kpncorp yields `[]`, so a screen that only needs options keeps working.
  - **`resolveName($raw, $names)`** — maps a raw corporate grouping string onto the master unit
    it names: exact case-insensitive first, then containment either way. This is what folds
    `locations.company_name` / `departments`/`designations.parent_company_id`'s
    "KPN Plantations" into the master's "Plantations". A value naming no master unit
    ("KPN Sugar", which the master does not carry) resolves to null and its children are
    dropped, since nothing could ever select them. It takes the name list rather than reading
    it, so resolving a whole table hits kpncorp once.
- **The NAME is what the app stores and compares**, not `kode_bisnis`: `nama_bisnis` is exactly
  what `employees.group_company` holds, which is what role scopes, the list filters and the
  master child tables are all matched against. No data migration was needed anywhere — the 5
  units present in the employee data match `nama_bisnis` verbatim, so the master is a clean
  superset. `kode_bisnis` is used only for ordering.
- **All 7 call sites switched** to `BusinessUnit::names()`: the Facecard / IDP / Report /
  Approval Layer list filters (`filterOptions()`, where every *other* option stays derived from
  the visible rows), the Role scope picker (`RoleController::scopeOptions()`, where company and
  location have no master of their own and stay derived), and the two IDP master helpers
  — `workLocationData()` (Master Training) and `orgHierarchyData()` (Master Implementation),
  both of which now resolve their group keys through `resolveName()`.
  - ⚠️ **Consequence, as intended**: the list filters now offer **Katingan** and **Others**,
    which match zero employees in the current data — filtering by one returns an empty list.
  - **Fixed in passing**: Master Training used to offer both "Plantations" (0 locations) and
    "KPN Plantations" (146). It is one unit now, with all 146 locations under it.
- **Competency type → business units** (the new field on the Master Data screen):
  `competency_type_business_units` (`..._create_competency_type_business_units_table`,
  reversible, round-trip verified) — id, `competency_type_id` FK cascade, `business_unit`
  string, unique per pair. Same shape as `training_business_units` /
  `implementation_business_units`: raw corporate names, not FKs (kpncorp is another
  connection). Model `CompetencyTypeBusinessUnit`, relation `CompetencyType::businessUnits()`,
  synced wholesale by `IdpMasterService::syncLinks()` via the existing `replaceValues()`.
  - **Required**: `business_units` is `required` for `competency_type` in
    `validateMaster()` (a training's units stay optional — only the shared rule grew a
    conditional). Nothing was backfilled, so the three existing types have no units until
    edited, and editing one now means picking at least one unit.
  - **Wire contract**: `competencyType()` gains a `businessUnits` prop (the master list) and
    each type payload carries `business_units`. The form posts through the **unchanged**
    shared `/idp-setting/masters` endpoints with `type=competency_type`.
  - **Vue** `Pages/MasterData/CompetencyType.vue`: a `MultiSelect` with select-all/clear-all
    (reusing Master Training's `businessUnitsPickHint` / `selectAllBusinessUnits` /
    `clearAllBusinessUnits` keys), a business-unit chip column in the table, search covering
    the units, and an amber flag on a stored unit the master no longer lists (kept selectable,
    so an unrelated edit never silently drops it). New keys `noBusinessUnits` /
    `unknownBusinessUnits` in `en`/`id`.
- **Not touched**: `EmployeeScopeService` still *matches* `employees.group_company` against a
  role's scope — that is filtering, not listing options, and the values are the same strings.
  Nothing reads a competency type's business units yet; the field records scope for later use.

## Phase 5.16 — Competency add/edit is its own page, not a drawer ✅ DONE (code + runtime-verified)
The competency form had outgrown a drawer over the list: a competency type, a bilingual name
+ description, any number of proficiency levels each with their own key behaviors, and the
active flag. It is a full page now — the list only links to it.

- **`Pages/MasterData/CompetencyForm.vue`** (new) — the whole form, in four numbered
  `FormSection` steps (type → bilingual name/description side by side → proficiency levels →
  status) with a bottom action bar pinned to the viewport (`lg:pl-[var(--sidebar-width)]`, so
  it lines up with the layout's content column) and a Back-to-list link in the page header.
  All the level-row machinery moved over unchanged — `levelRows` / `rowsFromIds` /
  `syncFormIds` / `pruneLevelRows`, the three-way proficiency mode, the "level switched off"
  amber flag. The one difference: there is no open/close step, so the form is seeded straight
  from the `competency` prop (null when adding) and `pruneLevelRows()` runs once at setup for
  legacy rows holding a level from another type.
- **`Pages/MasterData/Competency.vue`** shrank from 1406 to 768 lines. What stays is what
  belongs to a row rather than to a form: the grouped table, search + type filter, sort +
  paging, the active toggle, the status-history drawer and the delete dialog. "+ Competency"
  and the row's edit icon are `Link`s now.
- **Routes** — `GET /master-data/competency/create`, `POST /master-data/competency`,
  `GET /master-data/competency/{id}/edit`, `PUT /master-data/competency/{id}` (names
  `master_data.competency.create|store|edit|update`), all in the existing
  `permission:view_idp_master` group.
- **Why its own endpoints** rather than the shared `/idp-setting/masters` ones: only so a
  successful save can land back on the **list**. The shared endpoints `back()`, which from a
  form *page* means the form page. `storeCompetency` / `updateCompetency` are four lines each
  — they merge the type (the route fixes it, so the form no longer posts `type`), call the
  same `validateMaster()` and the same `IdpMasterService`, then
  `redirect()->route('master_data.competency')`. Validation failures still bounce back to the
  form with errors, as any Laravel redirect-back does. The shared endpoints are **untouched**
  and every other master screen still uses them — as does this list, for delete and the
  active toggle.
- **Controller**: `competencyPayload()` (one competency as both screens read it) and
  `competencyPickerData()` (the level + key-behavior option lists) extracted, so the list, the
  create page and the edit page cannot drift apart. The list still needs both lists — it
  resolves level and key-behavior names for its grouped rows.
- Locale: one new key, `backToList`, in `en`/`id`.
- ⚠️ **Still drawers**: Competency Type on the same menu, and every screen under IDP Settings.
  Only the competency form moved.

## Phase 5.17 — Sub-competencies on the competency form ✅ DONE (code + runtime-verified)
A competency now breaks down into any number of **sub-competencies**, each with a bilingual
name and an optional bilingual description, edited inline as a dynamic list on the competency
form (step 3, between the name and the proficiency levels).

- **`sub_competencies`** (`..._create_sub_competencies_table`, reversible, round-trip
  verified): id, `competency_id` FK **cascade**, `name_en`, `name_id` nullable,
  `description_en`/`description_id` text nullable, timestamps, unique
  (`competency_id`, `name_en`). The name is unique **inside its competency**, not globally —
  the parent's name already carries global uniqueness and two competencies may well break
  down into similarly named parts. Model `SubCompetency`; relation
  `Competency::subCompetencies()` ordered by id (creation order = display order).
- **Not a master.** No screen of its own, no `is_active`, no competency type, no
  `MasterDataType` case: rows live and die with their parent. That is why they are a child
  table rather than another entry in the master machinery.
- **Wire shape is the DB's own field names** (`name_en` / `name_id` / `description_en` /
  `description_id`), not the masters' `value_en` / `value_id` — there is no legacy
  single-table contract to preserve for a brand-new nested entity. Each row also carries its
  `id` when stored, absent when just added; that is how the server tells an update from an
  insert.
- **`IdpMasterService::syncSubCompetencies()`** — deliberately NOT the wholesale replace the
  other child lists use: a row that comes back with its own id is **updated in place**, so ids
  stay stable across an edit (renaming one part does not silently replace it with a new row).
  Rows with no id — **or an id belonging to another competency** — are created; rows the form
  no longer carries are deleted. Guarded by `presentKeys` like `related_programs`, so a caller
  that never sent the field cannot wipe the rows.
- **Two validation subtleties**
  - `IdpSettingController::dropUntouchedSubCompetencies()` runs **before** the rules (next to
    `applyTrainingName()`): a row the user added and never touched is not an error, it is a
    row they changed their mind about. Only rows blank in *all four* fields are dropped, so a
    description with no name still reports "needs an English name". The remaining keys are
    deliberately **not re-indexed** — errors come back keyed by position and the form still has
    the blank row on screen, so renumbering would pin an error to the wrong row.
  - `assertSubCompetencyNamesUnique()` catches two submitted rows sharing a name, which the
    table's unique index would otherwise surface as a database error rather than a field
    message. Custom messages replace the generated
    "The sub_competencies.0.name_en field is required" with per-row wording.
- **Label**: the competency form's name step said "Program Name" — it was reading the shared
  `idp.settings.name` key, which Development Model / Proficiency Level / Review Tools /
  Competency Type all use too. Rather than renaming that key for every screen, the competency
  form now uses a new `idp.settings.competencyName` ("Competency Name") for the step title and
  both name inputs. (Note `idp.form.competencyName` is a *different*, pre-existing key on the
  IDP plan drawer.)
- **Vue** `Pages/MasterData/CompetencyForm.vue`: `subRows` (local `uid` + the wire fields),
  an add button, per-row remove, EN/ID cards side by side, a filled-row count on the step
  header, and `subError(i, field)` to read the flat per-row error keys. The steps renumbered:
  type 1, name 2, **sub competency 3**, proficiency level 4, status 5. New locale keys
  `competencyName` / `subCompetencies` / `subCompetencyName` / `addSubCompetency` /
  `removeSubCompetency` / `noSubCompetenciesYet` in `en`/`id`.
- ⚠️ The list screen does **not** display sub-competencies yet — its grouped table is already
  competency × proficiency level × key behavior. The rows do ship in the list payload (both
  screens share `competencyPayload()`, which is what keeps them from drifting), so adding a
  column or a count chip later needs no server change.

## Phase 5.18 — A competency owns its proficiency ladder (free-typed) ✅ DONE (code + runtime-verified)
The competency form's proficiency step used to pin rows of the shared
`proficiency_levels` / `key_behaviors` masters through two pivots. A competency's ladder is
its own thing in practice, so it is now typed in on the form: an ordered list of rungs, each
with a bilingual name, a sequence number, an active flag, and any number of free-typed
bilingual key behaviors under it. Both lists are dynamic (add/remove a row at a time).

- **Tables** (`..._create_competency_owned_proficiency_levels`, reversible, round-trip
  verified with data): `competency_proficiency_levels` (competency_id FK **cascade**,
  `name_en`, `name_id`, `sequence` unsigned int, `is_active`, timestamps, unique
  (competency_id, name_en)) and `competency_key_behaviors`
  (`competency_proficiency_level_id` FK **cascade**, `name_en`, `name_id`, timestamps, unique
  (level_id, name_en)). Models `CompetencyProficiencyLevel` (with `HasActiveState` and the
  `$attributes` default) + `CompetencyKeyBehavior`.
  - ⚠️ **`sequence` has no unique index on purpose**: reordering rungs writes them one at a
    time and would collide with the index even though the end state is valid. (Phase 5.20
    made the number positional and server-assigned, so it can no longer collide at all; the
    index stays off for the same reason.)
  - ⚠️ **Mind the near-identical names**: `competency_proficiency_level` (singular) is the OLD
    pivot at master rows; `competency_proficiency_levels` (plural) is the new owned table.
- **Relations renamed for clarity** on `Competency`: `proficiencyLevels()` is now the owned
  `HasMany` (ordered by sequence, then id) — what the form and list read — and the pivots
  became `masterProficiencyLevels()` / `masterKeyBehaviors()`. The six call sites that read
  the pivots (the development-program screen, Master Implementation, the
  `assertLevelsActiveForCompetency` exemption, `IdpMasterService`) were repointed at the new
  names, so nothing changed for them.
- **The pivots are deliberately left in place and untouched.** Master Implementation narrows
  its level picker to the picked competency's *master* levels, and the development-program
  screen reads the same links; dropping them would empty those pickers. The competency form
  simply no longer writes them: `syncLinks()` now only touches the pivots when the caller
  actually posts `proficiency_level_ids` (`in_array($presentKeys)`), which is what stops the
  form's silence from wiping the existing links. Repointing those two screens at the owned
  ladder is a **follow-up slice** — the user accepted that explicitly.
- **The existing selections were copied across** by the migration: each competency's pinned
  master levels become owned rungs numbered 1..n in the masters' own order, carrying their
  names and active flag, with the competency's picked behaviors filed under the right rung.
  Locally that turned Synergy's two pinned levels into `1=PL1 [B2, B3]`, `2=PL2 []`. Because
  the pivots survive, `down()` just drops the two tables and re-running the migration rebuilds
  the same rows.
- **`IdpMasterService::syncOwnedProficiencyLevels()` / `syncOwnedKeyBehaviors()`** — the same
  id-preserving contract as the sub-competencies, one level deeper: a row that comes back with
  its own id is updated in place (so renaming or renumbering a rung keeps its identity, and
  its activation history keeps pointing at the same row), rows with no id — or an id belonging
  to another competency — are created, and rows the form no longer carries are deleted, taking
  their behaviors with them. A rung or behavior with a blank name is dropped rather than saved.
- **Activation history, as on the master screens**: switching a rung on or off writes to the
  same `storage/app/audit/master-status-YYYY-MM.jsonl` under the new subject
  `MasterStatusAudit::COMPETENCY_LEVEL`, and only on a **transition** (re-saving an unchanged
  ladder adds nothing). A rung created switched off is recorded, like a master created
  inactive. `GET /master-data/competency/levels/{level}/status-history` feeds the shared
  `MasterStatusHistory` drawer, opened per rung from a clock icon (only on stored rungs — a
  new one has no trail yet).
- **Validation** mirrors the sub-competencies: `dropUntouchedProficiencyLevels()` runs before
  the rules (a rung, or a behavior, the user added and never filled in is dropped, and keys
  are not re-indexed so per-row errors stay pinned to the right row), then per-row `required`
  / `max` rules with readable custom messages, plus the consistency guard for duplicate rung
  names, duplicate sequence numbers, and duplicate behavior names inside one rung.
- **Wire contract**: `competencyPayload()` swaps `proficiency_level_ids` / `key_behavior_ids`
  for a nested `proficiency_levels` list (`{id, name_en, name_id, sequence, is_active,
  key_behaviors: [{id, name_en, name_id}]}`), in the DB's own field names like the
  sub-competencies. `competencyPickerData()` is **gone** — neither the form nor the list needs
  the master option lists any more, so both pages lost the `proficiencyLevels` /
  `keyBehaviors` props.
- **Vue**: `CompetencyForm.vue` step 4 is rebuilt — the three-way proficiency mode selector,
  the type-scoped level dropdowns, the "level switched off" flag and all the
  `levelRows`/`pruneLevelRows` machinery are gone, replaced by `ladderRows` (uid + wire
  fields, nested `key_behaviors`), a sequence input that auto-fills the next free number, an
  inline active toggle, the per-rung history button, and add/remove at both levels.
  `Competency.vue`'s grouped table now walks the nested rows instead of resolving master ids
  (`rowName()` reads `name_en`/`name_id`), and the level chip shows its sequence number and
  goes struck-through grey when the rung is inactive. New locale keys `sequence` /
  `removeProficiencyLevel` / `noProficiencyLevelsYet` / `removeKeyBehavior` /
  `noKeyBehaviorsYet` in `en`/`id`; `addProficiencyLevel`, `addKeyBehavior`, `keyBehaviors`,
  `activeLabel`, `inactiveBadge` and `statusHistory` already existed.
- ⚠️ The `/idp-setting/proficiency-level` master screen survived this slice and kept managing
  the shared catalogue the other screens read. **Phase 5.19 retired it**, so a competency's
  ladder is now the only kind of proficiency level there is.

## Phase 5.19 — The proficiency-level master is retired ✅ DONE (code + runtime-verified)
`/idp-setting/proficiency-level` is gone, and so is the `proficiency_levels` catalogue behind
it. A proficiency level was never a catalogue of its own: it is a rung on one competency's
ladder, which is what `competency_proficiency_levels` has stored since Phase 5.18. The three
screens that still selected from the shared master — **Master Implementation**, **Master
Training** and the **development-program** form — each pick a competency first, so each now
reads that competency's own ladder. This is the follow-up slice Phase 5.18 deferred.

- **Migration** `..._drop_proficiency_level_master` (reversible, round-trip verified with
  data): drops `proficiency_levels`, `key_behaviors` and the two pivots that pinned master
  rows onto a competency (`competency_proficiency_level`, `competency_key_behavior`), and
  repoints the three references — `implementation_proficiency_level`,
  `proficiency_level_training` and `development_programs.proficiency_level_id` — at
  `competency_proficiency_levels`. **The column names are unchanged**; each value is remapped
  onto the owning competency's rung **of the same name**, and a reference with no matching
  rung is dropped, since nothing could resolve it.
  - `down()` restores the **meaning**, not the original rows (the Phase 5.4 convention): the
    master is rebuilt from the ladders, one row per (competency type, name), the pivots are
    refilled, and the three references point back at it. Descriptions and the original ids
    are not recoverable — nothing carries them any more, so a rollback yields a smaller
    catalogue than the one that was dropped.
- **`MasterDataType`** loses its `ProficiencyLevel` and `KeyBehavior` cases, so
  `type=proficiency_level` / `key_behavior` are no longer accepted wire values anywhere (the
  `{type}` route binding 404s on them). Models `ProficiencyLevel` / `KeyBehavior`, the
  `CompetencyType::proficiencyLevels()` relation and `Competency::masterProficiencyLevels()` /
  `masterKeyBehaviors()` are deleted; `Competency::proficiencyLevels()` — the owned ladder —
  is the only one left, which is why Phase 5.18's `master*` naming could go.
- **Wire contract**: `proficiencyLevels` is still the prop name on all three screens, but it
  now lists **every competency's rungs**, each carrying `competency_id` + `sequence`
  (`IdpSettingController::proficiencyLevelOptions()`, shared by the three). A screen narrows
  it by the competency it has chosen, and needs the whole list to name a level a row already
  stores. `competencies[].proficiency_level_ids` are that competency's own rungs.
- **Master Training changed shape**: its level picker used to be scoped by the competency
  **type** (a level filed elsewhere was off the list, an untyped one was global). Levels have
  no type any more, so it is scoped by the **competency** — the field stays locked until one
  is picked (`pickCompetencyFirst`), and a `loadingForm` guard keeps the new
  competency→levels cascade from blanking a row being loaded, the same guard Master
  Implementation already had. Master Implementation and the development-program form needed
  no behavioural change: they already cascaded through the competency and the implementation
  map respectively.
- **Server mirror**: `assertLevelsBelongToCompetency()` (new, shared by the training and
  implementation checks) rejects a level that is not a rung of the chosen competency — with
  **no exemption**, since a level's owner is stable and a mismatch means the pick has to be
  redone. `assertLevelsActive()` reads the owned table now and keeps its exemption for what
  the row already stores. The competency form's old "levels must belong to the chosen type"
  and `assertLevelsActiveForCompetency()` checks are gone with the pivots.
- **Two new delete guards**, both because a competency now *owns* what other screens point at:
  - `assertRemovedLevelsUnused()` — a rung the competency form drops cannot be one an
    implementation, a training or a development program still points at; the row would go and
    take the link (or the program's level) with it. Only rungs actually removed are checked,
    so renaming or renumbering a rung is unaffected.
  - `IdpMasterService::deletionBlocker()` — a competency that a development program develops
    can no longer be deleted, alongside the existing implementation / training guards.
- **Deleted**: `Pages/Idp/ProficiencyLevel.vue`, the route
  `GET /idp-setting/proficiency-level`, `IdpSettingController::proficiencyLevel()`, the nav
  item, and 14 now-dead locale keys in `en`/`id` (`proficiencyLevelTitle` /
  `proficiencyLevelSubtitle` / `searchProficiencyLevel` / `proficiencyLevels` /
  `proficiencyLevelsHint` / `editProficiencyLevel` / `deleteProficiencyLevel` /
  `levelTypePickHint` / `manageKeyBehaviors` / `editKeyBehavior` / `deleteKeyBehavior` /
  `noKeyBehaviors` / `noProficiencyLevelsForType` / `noActiveProficiencyLevelsForType`).
- Verified against the local DB: the migration round-trips (the one implementation link
  remaps to its competency's identically named rung and back); all three payloads ship the
  new shape; and, in a rolled-back transaction — an implementation/training saving its own
  competency's rungs passes, one borrowing another competency's rung is rejected, dropping a
  mapped rung from the ladder is rejected while an unchanged ladder saves, and newly pinning
  an inactive rung is rejected.
- ⚠️ **Operational note**: the local catalogue held 7 master levels while the only competency's
  ladder had 2 rungs, so the drop discarded 5 rows nothing referenced. Anywhere with real
  data, a master level that no competency's ladder names by the same string is **not**
  carried over — check the ladders before running this in an environment that matters.

## Phase 5.20 — Rung descriptions + positional ordering with up/down ✅ DONE (code + runtime-verified)
Two changes to the proficiency ladder on the competency form. A rung now carries a bilingual
**description**, like every other master with a name worth explaining. And **both** lists —
the rungs and the key behaviors under each — are ordered by **row position**, moved with
up/down buttons, instead of the rungs' typed sequence number.

- **Migration** `..._add_description_and_key_behavior_sequence` (reversible, round-trip
  verified): `description_en` / `description_id` (TEXT, nullable) on
  `competency_proficiency_levels`, and `sequence` (unsigned int, default 1) on
  `competency_key_behaviors`, backfilled by id so existing behaviors keep the order they were
  created in. Neither `sequence` column carries a unique index — a reorder writes the rows one
  at a time, and the number is now derived rather than asserted, so there is nothing to police.
- **The sequence is server-assigned, not posted.** `IdpMasterService::syncOwnedProficiencyLevels()`
  / `syncOwnedKeyBehaviors()` number the rows `1..n` from the submitted order. The counter runs
  over the rows actually **kept**, not the array keys: `dropUntouchedProficiencyLevels()`
  deliberately does not re-index after dropping blank rows (so per-row errors stay pinned to
  the row on screen), and using the key would leave gaps. A blank-named row is skipped without
  consuming a number.
- **Wire contract**: both `sequence` fields now travel **one way**. `competencyPayload()` still
  ships them (the list screen shows the rung's number) and the level gains
  `description_en` / `description_id`, each key behavior gains `sequence`; the form drops the
  numbers on the way in (`Omit<…, 'sequence'>`) and never sends them back. The
  `proficiency_levels.*.sequence` rules and their three custom messages are gone, replaced by
  `description_en` / `description_id` rules.
- **`assertProficiencyLadderConsistent()`** lost its duplicate-sequence check — two rungs
  cannot share a number that neither of them chooses. The duplicate-**name** checks (rungs
  within a competency, behaviors within a rung) stay: those are real unique indexes.
- **`dropUntouchedProficiencyLevels()`** now counts a description as "touched": a rung is only
  dropped when its name AND description are blank in both languages and it has no named
  behavior. So a rung with a description but no name still reports "needs an English name"
  rather than vanishing — the same rule the sub-competencies follow.
- **Vue** `Pages/MasterData/CompetencyForm.vue`: the rung header's number input became a
  read-only position badge plus a paired up/down control (disabled at either end, so the
  buttons never jump about); the same control sits beside each key behavior's remove button.
  One shared `moveRow(rows, index, delta)` backs both. A bilingual description block was added
  under the rung's names, matching the sub-competency cards. `nextSequence()` is gone.
- **Vue** `Pages/MasterData/Competency.vue`: the grouped table prints a rung's description as a
  muted line under its chip (`rowDescription()`, the same preferred-then-fallback rule as
  `rowName()`), so a description written on the form is visible without opening it.
- Locale: new `moveUp` / `moveDown` in `en`/`id`. `sequence` is kept — it now labels the
  position badge.
- Verified against the local DB in a rolled-back transaction: reversing the rungs and one
  rung's behaviors renumbers both `1..n` **keeping every id**, descriptions save in both
  languages, an untouched blank row is dropped without consuming a number, a rung appended
  with no number lands last, and a row carrying only a description is still rejected for
  having no name.

## Phase 5.21 — Model package + its development models are one page, one save ✅ DONE (code + runtime-verified)
`/idp-setting/development-model` was two stacked tables (packages on top, the selected
package's models below) with two drawers and per-model endpoints. A package's models only
mean anything **as a set** — their percentages have to total 100% — so they are now created,
re-weighted and removed together with the package that holds them, on a form page of its own.

- **The list is one table.** Packages only; each row has a chevron that expands its
  development models underneath (weighting bar + one line per model with its description and
  its program / plan usage counts). Several rows may be open at once, and the **active**
  package opens on arrival. Add / edit are `Link`s to the form page; delete stays inline.
- **The form is its own page** (`Pages/Idp/DevelopmentModelForm.vue`), the same shape
  `MasterData/CompetencyForm.vue` uses: numbered `FormSection` steps and a bottom action bar
  pinned to the viewport. Step 1 is the package — name + start + end on **one full-width row**
  (`lg:grid-cols-4`, the name spanning two), with the active-pin card below it spanning the
  width; step 2 is the models as a dynamic row list, above a **live** weighting bar that says
  how much is left to allocate or how far over the split is.
- **A model row is a header + two equal language halves.** The header carries what the model
  *is* — its position badge, its name read-back, the in-use chip, the **percentage**, and the
  remove button; the body is EN and ID side by side (`lg:grid-cols-2`), each a name plus a
  description. The percentage sits in the header rather than in a third narrow column beside
  the two tall language blocks, where it was stranded at the top; its error takes a full-width
  line under the header so it never squeezes the row.
- **The 100% rule is what gates the save.** Save is disabled (with the reason beside it)
  until the rows total exactly 100, and `assertModelsConsistent()` mirrors that server-side —
  a partial split is not saveable at all, where before each model was only capped at ≤100%
  cumulatively. Two models sharing a name are rejected per row (the DB's unique index would
  otherwise surface as a database error).
- **Routes**: `GET /idp-setting/packages/create`, `GET …/{package}/edit` (names
  `idp.setting.packages.create|edit`) join the existing POST / PUT / DELETE, which now
  `redirect()->route('idp.setting.development_model')` so a save lands back on the list.
  **The three `/idp-setting/models/*` endpoints are gone** — nothing else used them, and the
  `replace_with` reassign they carried was never reachable from the UI. `StoreDevelopmentModelRequest`,
  `UpdateDevelopmentModelRequest`, `App\Rules\SumPercentageCheck` (the old ≤100 cap) and the
  empty scaffold `DevelopmentModelController` went with them.
- **`syncPackageModels()`** follows the same id-preserving contract the competency form's
  nested rows do: a row that comes back with its own id is updated in place (so renaming or
  re-weighting a model keeps its identity, and the plans and programs pointing at it keep
  pointing at it), rows with no id — or an id belonging to another package — are created, and
  rows the form no longer carries are deleted (soft, as before). Both writes run in a
  transaction. The canonical `name` stays in step with `name_en`.
- **Two validation subtleties**, both borrowed from the competency form:
  - `prepareForValidation()` drops rows blank in all four text fields — a row the user added
    and thought better of is not an error. The keys are deliberately **not** re-indexed, since
    errors come back keyed by position and the blank row is still on screen.
  - `assertRemovedModelsUnused()` rejects dropping a model an IDP plan or a development
    program still points at, naming it. Only rows actually removed are checked, so renaming
    or re-weighting is unaffected; a create never triggers it.
- **`development_models.uses_master_training`** (boolean, default false;
  `..._add_uses_master_training_to_development_models_table`, reversible) — a checkbox
  ("Master Training") on each model row, spanning both language columns since it is a
  property of the model rather than of either language. It says that what this model develops
  is drawn from the **Master Training** catalogue instead of being written out by hand.
  - **The flag recorded intent only when it landed** — nothing read it. **Phase 5.23** wired
    it up: it is now what decides where a development program's name and description come
    from, replacing Phase 5.6's per-program name-source switch.
  - `DevelopmentModel` casts it and declares `protected $attributes = ['uses_master_training'
    => false]`, the same reason the `HasActiveState` models do: without it a model created
    without the field has no such attribute in memory at all and reads as null.
  - `prepareForValidation()` normalizes the wire value through `filter_var(…,
    FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` before the `boolean` rule sees it.
    Inertia posts JSON, so a real bool arrives — but a form-encoded post would send
    `"true"`/`"false"`, which Laravel's `boolean` rule rejects. A value meaning neither
    becomes null and still fails the rule.
  - The list page's expanded panel shows a primary-tinted "Master Training" chip on a flagged
    model, beside its percentage.
- **Wire contract**: the form's `package` prop is `{id, name, start_date, end_date,
  is_current, models: [{id, name_en, name_id, percentage, uses_master_training,
  description_en, description_id, development_programs_count,
  individual_development_plans_count}]}` — the DB's own field names, nested, like the
  competency form's rows. The counts are what let a row that cannot be removed say so before
  the save is attempted. The list page's props keep their shape (the models are whole
  `DevelopmentModel` rows, so they carry the new column automatically).
- **`ClientTable`** gained expandable rows: an `expandedKeys` prop (the parent owns which rows
  are open) and an `expanded` slot rendered as a full-width row underneath. Fixed in passing:
  the `numbered` column used the `perPage` **prop** rather than the live page size, so the
  running number was wrong after changing rows-per-page.
- **New package defaults to 70-20-10** — three rows pre-weighted, so the common case is
  naming them rather than building the shape.
- Locale: new `packageDetails` / `showModels` / `hideModels` / `editModels` / `remaining` /
  `over` / `mustTotal100` / `modelName` / `untitledModel` / `removeModel` / `inUse` /
  `modelInUsePlans` / `modelInUsePrograms` / `useMasterTraining` / `useMasterTrainingHint`
  in `en`/`id`; the four keys the drawers owned (`editModel`, `deleteModel`, `targetPackage`,
  `adjust`) deleted.
- Verified against the local DB in a rolled-back transaction: a 100% set saves with its
  models; 90% and 110% are rejected naming the total; no models at all is rejected; duplicate
  names flag both rows; a blank row is dropped rather than erroring; an edit that renames,
  re-weights and drops an unused model keeps the kept rows' ids and soft-deletes the dropped
  one; dropping a model a development program points at is rejected while dropping its unused
  sibling passes; and the overlap + active-pin rules still hold. All three page renders
  (`create`, `edit`, list) were checked for component name and props.

## Phase 5.22 — Unsaved-changes guard on every drawer form ✅ DONE (code)
Closing a drawer throws its draft away. The IDP plan drawer already asked before doing that;
no other drawer did — a backdrop click or Escape silently discarded a half-filled form. Every
drawer that holds a form now raises the same keep/discard prompt, driven by two shared pieces.

- **`Composables/useUnsavedGuard.ts`** — `useUnsavedGuard(form, close)` returns
  `{ confirming, requestClose, discard }`: `requestClose()` raises the prompt when the form is
  dirty and closes straight away when it is not. The caller keeps ownership of the drawer's
  open state and of what closing *means* (reset the form, clear the row id, …); the guard only
  decides whether the question gets asked. A page with several form drawers calls it once per
  form (`confirmingEdit` / `confirmingImport`, …).
- **`seedForm(form, values)`** (same file) — the load half, and the part that is easy to get
  wrong: `isDirty` measures the distance from the form's **defaults**, so a drawer that loads
  an existing row must seed those defaults as it opens (`defaults()` → `reset()` →
  `clearErrors()`). Assigning the fields alone — which is what every screen did — leaves the
  form dirty from the first render, so it would have prompted on a drawer nobody touched.
  Seeding also assigns every field in ONE synchronous block, which is why cascade watchers
  (they flush afterwards) see parents and children already consistent.
- **`Components/Domain/UnsavedChangesDialog.vue`** — the prompt itself, wrapping
  `ConfirmDialog` with the shared title / Discard / Keep editing labels and reading its own
  locale, so a call site is four lines. An optional `message` overrides the generic wording
  (the plan drawer keeps its "This plan has unsaved changes").
- **`ConfirmDialog`** gained an optional `icon` prop: a `danger` dialog is not always a
  deletion, and the unsaved-changes prompt is a warning rather than a trash can.
- **Locale**: `discardTitle` / `discardMessage` / `discardConfirm` / `keepEditing` moved into
  the shared `common` block (non-IDP screens use them too); `idp.form` keeps only its own
  `discardMessage` — its `discardTitle` / `discardConfirm` / `keepEditing` were deleted.
- **Wired into all 15 form drawers**: IDP plan / Excel upload / approve-reject (`IdpPanel`,
  three), Master Training, Master Implementation, Master Competency Type, IDP Settings
  (development program), Review Tools, Roles, Approval Layer (superior chain + import, two),
  Approvals Inbox, User Guide upload, and the three Facecard profile sections (competency
  scoring, nine-box add + edit, succession/result summary). Read-only drawers — the activation
  history, the approval-chain detail, the layer change log — deliberately do **not** prompt.
- **Fixed in passing**, all consequences of `isDirty` now being load-time accurate:
  - **Cascade watchers that ran on load.** Master Training's competency-type→competency and
    business-unit→work-location watchers, and Master Implementation's competency→levels and
    type→competency watchers, were not covered by their screens' `loadingForm` guard. Harmless
    while fields were assigned one at a time; with `reset()` driving `isDirty` a stored pair
    that has since drifted apart — or a work location kpncorp no longer offers — would be
    silently blanked on open *and* leave the form dirty. All four return early while a row is
    loading, matching the server rule that what a row already stores is exempt.
    (Master Implementation's `loadingForm` was declared below the watchers that now read it, so
    it was hoisted to the top of the form block.)
  - **Two drawers read their values once, at page load** (the profile's succession form and the
    Result Summary section) — a save followed by a re-open showed stale data. Both read off the
    props each time they open now.
  - `IdpPanel`'s `defineExpose({ openUpload })` is a real function rather than an inline
    `uploadOpen = true`, so the upload drawer seeds its form like every other.
- ⚠️ Pressing **Escape** with the prompt open reaches both the prompt and the drawer behind it
  (each listens on `document`). The net effect is the intended one — the prompt closes and the
  drawer stays open, i.e. "keep editing" — but it relies on the drawer's listener registering
  first (template order). Worth knowing before changing either component's key handling.
- ⚠️ Verified by `npm run build` plus a one-off `vue-tsc --noEmit` (the project ships no
  tsconfig and no type-check step, so a scratch strict config was used and then removed):
  nothing in the new or changed code errors. Runtime click-through was NOT done.
- ⚠️ Noticed, not fixed (out of scope): `idp.settings.searchCompetencyType` is declared twice
  in both `en.ts` and `id.ts` (same value, so no behavioural difference) — a duplicate-key
  error under any future type-check.

## Phase 5.23 — A development program's name + description come from its model ✅ DONE (code + runtime-verified)
The development-program drawer's **Program identity** step carried a two-way switch —
*Program name* (type it) vs *Master training* (pick one) — introduced in Phase 5.6. That is
the development model's decision, not the program's: `development_models.uses_master_training`
(Phase 5.21) already says a model's programs are drawn from the Master Training catalogue. The
switch is gone; the identity step reads the flag on the model picked in step 2.

- **The rule**: a program filed under a model flagged `uses_master_training` **must** name a
  training, and its name *and description* are copied off that training. Under any other model
  — or none at all, since the model is still optional — both are typed in the bilingual fields.
- **`development_programs.description_en` / `description_id`** (TEXT, nullable;
  `..._add_description_to_development_programs_table`, reversible, round-trip verified) — a
  program had no description at all before. TEXT for the same reason the name is: a program
  reads as an activity description. Flipping `MasterDataType::DevelopmentProgram->hasDescription()`
  to true is what wires up the write (`IdpMasterService::attributes()`) and the existing
  `description_en` / `description_id` rules; neither was touched.
- **`ProgramMasterRules`** gained `requiresTraining(Request)` (reads the posted
  `development_model_id`) and `prepare()` now branches on it: a typed model forces
  `training_id` to null **whatever the request carried**, and a master-training model copies
  the training's `name_en` / `name_id` **and both descriptions** onto the request before the
  rules run — so a training-sourced program is policed by the same required / length /
  unique-per-model rules as a typed one. As before, the copy happens on **every** save, so
  re-saving picks up a training that has since been renamed, and there is deliberately no
  automatic cascade from a training rename to its programs.
- **One error, not two.** `MasterDataValidator::rules()` makes `training_id` `required` when
  the model demands one, and — only in that case, and only while none is picked — relaxes
  `value_en` to `nullable`. Without that relaxation a master-training program saved with no
  training reported "the value en field is required" for a field the form does not even show.
  Nothing can save on that path: `training_id.required` fails first, with a message naming the
  model's setting.
- **Wire contract**: each program payload gains `description_en` / `description_id`, and the
  `trainings` option list gains them too (so the drawer can read back exactly what will be
  stored — the server copies them again on save, which is what the stored values rely on).
  The form still posts `training_id` alongside `value_en` / `value_id` / `description_*`; the
  server is the authority whenever the model says a training supplies them.
- **Vue** `Pages/Idp/Settings.vue`: the radio cards, `nameSource`, `nameSourceOptions` and
  `showNameInputs` are gone, replaced by a `usesMasterTraining` computed read off
  `modelById`. A watcher on it stashes the typed text (`typedText` — name **and**
  description, replacing `typedName`) when the program moves onto a master-training model and
  restores it on the way back, so switching models never loses what was written; the
  `applyingOpen` guard keeps it from firing while a row is being loaded. The training
  read-back card now shows the description alongside the name, and the typed branch gained a
  bilingual description block (line breaks kept, unlike the name, which swallows Enter).
- **Locale**: `nameSource` / `nameSourceProgram` / `nameSourceTraining` deleted; new
  `nameFromModelTraining` (the note explaining why the step shows a training picker) and
  `programDescriptionPlaceholderEn` / `programDescriptionPlaceholderId` in `en`/`id`.
- Verified against the local DB in a rolled-back transaction: a master-training model with no
  training reports exactly one error (the training, not the name); with a training, the name
  and both descriptions are taken from it and whatever the wire carried is ignored; a typed
  model stores the typed values and nulls a posted `training_id`; a typed model with no name
  still fails on the name; re-saving picks up a renamed training; moving a training-sourced
  program onto a typed model drops the training and keeps the newly typed text; and both
  payloads ship the new fields.
- ⚠️ The program **table** does not show the description — the list is program × model ×
  competencies × scope. It ships in the payload, so adding a column later needs no server
  change.

## Deployment — the app sits behind a TLS-terminating proxy
Staging and production serve the app over https through a reverse proxy that talks plain
http to PHP. Two things make Laravel aware of that, and **both** matter:

- **`bootstrap/app.php` trusts the proxy** (`$middleware->trustProxies(at: '*')`). Without it
  `$request->isSecure()` is false, `$request->ip()` is the proxy's address, and every
  absolute URL the framework builds is `http://` on an `https://` page.
- **`APP_URL` must carry the real scheme + host.** `AppServiceProvider::boot()` calls
  `URL::forceScheme('https')` when it starts with `https://`, as a backstop for a proxy that
  does not send `X-Forwarded-Proto`. It reads `config('app.url')`, not `env()`, so it
  survives `config:cache`.

⚠️ **The symptom when this is wrong is misleading.** Most writes here return `back()`, which
builds its `Location` from the `Referer` header — already https — so nothing surfaces the
problem. A handler that redirects to a **named route** instead (the development-model package
form and the competency form both do, so a save lands on the list) emits
`Location: http://…`; the browser refuses to follow an https → http redirect from an XHR, and
Inertia reports it as `HttpNetworkError: Network error` with no status code. **The write itself
already succeeded** — only the redirect is blocked, so the screen looks broken while the data
is saved.

## Entity map (facecard → kpn-tmp)
App-owned: CompetencyAssessment, DevelopmentModel, Competency, CompetencyType,
DevelopmentProgram, ReviewTool, Training, CompetencyImplementation, IndividualDevelopmentPlan, ResultSummary, MatrixGradeConfig,
JobStatus, PerformanceAppraisal, ImportLog, UserGuide, ImplementationBusinessUnit,
CompetencyTypeBusinessUnit, SubCompetency, CompetencyProficiencyLevel,
CompetencyKeyBehavior, User + Spatie Role/Permission.
`kpncorp` (read-only): Employee, BusinessUnit (`master_bisnisunits` — the source of truth for
every business-unit dropdown), FormalEducation, WorkExperience, TrainingCertification,
MovementTransaction, PromotionTransaction.
