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

### Missing composer deps to add when their feature lands
`maatwebsite/excel` (imports/exports), `barryvdh/laravel-dompdf` + `spatie/browsershot` (PDF).

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
  linking kept in sync on create/update/delete.
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

### Phase 3 — what's left (needs uninstalled deps)
- PDF export (facecard single/bulk, IDP template/master) — `barryvdh/laravel-dompdf` +
  `spatie/browsershot`.
- Excel Imports/Exports (Import Center parsing, IDP Excel import) — `maatwebsite/excel`.
- `GenerateIdpZip` bulk-export queue job + status polling (Phase 4).
- Employee photo upload/download.
These are the Phase 4 / dependency-gated items; every screen and workflow that does not need
them is built and wired.

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

## Entity map (facecard → kpn-tmp)
App-owned: CompetencyAssessment, DevelopmentModel, DevelopmentPlanMaster,
IndividualDevelopmentPlan, ResultSummary, MatrixGradeConfig, JobStatus, PerformanceAppraisal,
ImportLog, UserGuide, User + Spatie Role/Permission.
`kpncorp` (read-only): Employee, FormalEducation, WorkExperience, TrainingCertification,
MovementTransaction, PromotionTransaction.
