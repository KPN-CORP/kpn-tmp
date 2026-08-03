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

### Still open for later phases
- Replace placeholder nav (`activity`, `users`, `reports`) and placeholder routes in
  `routes/web.php` with the real HR menu (Phase 2).
- Composer deps (`maatwebsite/excel`, `dompdf`, `browsershot`) intentionally NOT installed
  yet — add each when its feature (Excel/PDF) lands.
- Site/company-scoped roles (the many `HC Site …` / `PIC/Admin …` roles) are runtime data
  created via the admin UI, not seeded.

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
