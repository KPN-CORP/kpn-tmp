<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDevelopmentModelPackageRequest;
use App\Http\Requests\StoreDevelopmentModelRequest;
use App\Http\Requests\UpdateDevelopmentModelPackageRequest;
use App\Http\Requests\UpdateDevelopmentModelRequest;
use App\Models\DevelopmentModel;
use App\Models\DevelopmentModelPackage;
use App\Models\DevelopmentPlanMaster;
use App\Models\Employee;
use App\Models\IndividualDevelopmentPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IdpSettingController extends Controller
{
    public function index(): Response
    {
        $programs = DevelopmentPlanMaster::where('type', 'development_program')
            ->with('developmentModel:id,name,name_en,name_id,percentage')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'development_model_id', 'competency_type_id', 'proficiency_level_id', 'custom_competency', 'custom_proficiency_level', 'grade', 'grades']);

        $programValues = $programs->keyBy('id');

        $competencies = DevelopmentPlanMaster::where('type', 'competency_name')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'description_en', 'description_id', 'related_program', 'competency_type_id', 'proficiency_level_id'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'value' => $c->value,
                'value_en' => $c->value_en,
                'value_id' => $c->value_id,
                'description_en' => $c->description_en,
                'description_id' => $c->description_id,
                'competency_type_id' => $c->competency_type_id,
                // The competency's chosen proficiency level — the program form
                // derives its proficiency options from the selected competencies.
                'proficiency_level_id' => $c->proficiency_level_id,
                'related_program' => collect($c->related_program ?? [])->map(fn ($id) => (int) $id)->values(),
                'linked_programs' => collect($c->related_program ?? [])
                    ->map(fn ($id) => $programValues->get($id)?->value)
                    ->filter()
                    ->values(),
            ]);

        [$packages, $activePackageId] = $this->packagesData();

        return Inertia::render('Idp/Settings', [
            'packages' => $packages,
            'activePackageId' => $activePackageId,
            'developmentModels' => $this->developmentModelsData(),
            'competencies' => $competencies,
            'competencyTypes' => $this->competencyTypesData(),
            'proficiencyLevels' => DevelopmentPlanMaster::where('type', 'proficiency_level')
                ->orderBy('value')->get(['id', 'value', 'value_en', 'value_id']),
            // Corporate scope option list for the program form (grades), read
            // defensively so an unreachable kpncorp never breaks the settings screen.
            'grades' => $this->gradeOptions(),
            'developmentPrograms' => $programs->map(fn ($p) => [
                'id' => $p->id,
                'value' => $p->value,
                'value_en' => $p->value_en,
                'value_id' => $p->value_id,
                'development_model_id' => $p->development_model_id,
                'model_name' => $p->developmentModel?->name,
                'competency_type_id' => $p->competency_type_id,
                'proficiency_level_id' => $p->proficiency_level_id,
                // Free-typed competencies / proficiency (used when the type is "Others").
                'custom_competency' => $p->custom_competency,
                'custom_proficiency_level' => $p->custom_proficiency_level,
                // A program is scoped to any number of grades; older rows that
                // only carry the singular pin still surface as a one-item list.
                'grades' => ! empty($p->grades)
                    ? array_values($p->grades)
                    : array_values(array_filter([$p->grade], fn ($v) => $v !== null && $v !== '')),
            ]),
        ]);
    }

    /**
     * Review tools management — a standalone master list (bilingual name), split
     * onto its own page from the master-data settings screen.
     */
    public function reviewTools(): Response
    {
        return Inertia::render('Idp/ReviewTools', [
            'reviewTools' => DevelopmentPlanMaster::where('type', 'review_tools')
                ->orderBy('value')->get(['id', 'value', 'value_en', 'value_id']),
        ]);
    }

    /**
     * Development model management (packages + weighted models), split onto its
     * own page from the master-data settings screen.
     */
    public function developmentModel(): Response
    {
        [$packages, $activePackageId] = $this->packagesData();

        return Inertia::render('Idp/DevelopmentModel', [
            'packages' => $packages,
            'activePackageId' => $activePackageId,
            'developmentModels' => $this->developmentModelsData(),
        ]);
    }

    /**
     * Competency management (competencies + competency types), split onto its
     * own page from the master-data settings screen.
     */
    public function competency(): Response
    {
        $competencies = DevelopmentPlanMaster::where('type', 'competency_name')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'description_en', 'description_id', 'competency_type_id', 'proficiency_level_id', 'key_behavior_id', 'proficiency_level_ids', 'key_behavior_ids'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'value' => $c->value,
                'value_en' => $c->value_en,
                'value_id' => $c->value_id,
                'description_en' => $c->description_en,
                'description_id' => $c->description_id,
                'competency_type_id' => $c->competency_type_id,
                // Multi-selected proficiency levels + key behaviors, falling back
                // to the legacy singular pins for rows created before the change.
                'proficiency_level_ids' => ! empty($c->proficiency_level_ids)
                    ? array_map('intval', $c->proficiency_level_ids)
                    : array_values(array_filter([$c->proficiency_level_id], fn ($v) => $v !== null)),
                'key_behavior_ids' => ! empty($c->key_behavior_ids)
                    ? array_map('intval', $c->key_behavior_ids)
                    : array_values(array_filter([$c->key_behavior_id], fn ($v) => $v !== null)),
            ]);

        return Inertia::render('Idp/Competency', [
            'competencies' => $competencies,
            'competencyTypes' => $this->competencyTypesData(),
            'proficiencyLevels' => DevelopmentPlanMaster::where('type', 'proficiency_level')
                ->orderBy('value')->get(['id', 'value', 'value_en', 'value_id']),
            // Key behaviors carry their parent proficiency_level_id so the
            // competency form can scope the behavior dropdown to the chosen level.
            'keyBehaviors' => DevelopmentPlanMaster::where('type', 'key_behavior')
                ->orderBy('value')->get(['id', 'value', 'value_en', 'value_id', 'proficiency_level_id']),
        ]);
    }

    /**
     * Master Implementation — maps a single competency (+ its proficiency level)
     * to a corporate org scope: grade plus a dynamic business-unit hierarchy
     * (business unit → job family / function → position). Set up here before the
     * Master Development screen. Stored as `development_plan_masters` rows with
     * type='implementation'.
     */
    public function masterImplementation(): Response
    {
        $implementations = DevelopmentPlanMaster::where('type', 'implementation')
            ->orderByDesc('id')
            ->get([
                'id', 'competency_type_id', 'competency_name_id', 'proficiency_level_id',
                'grade', 'business_unit', 'job_family', 'function_name', 'position',
            ]);

        // Competencies carry their type + chosen proficiency level so the form
        // can cascade (type → competency → proficiency level).
        $competencies = DevelopmentPlanMaster::where('type', 'competency_name')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'competency_type_id', 'proficiency_level_id']);

        // Proficiency levels carry their key behaviors so the form can surface
        // the behaviors of the competency's level.
        $proficiencyLevels = DevelopmentPlanMaster::where('type', 'proficiency_level')
            ->with(['keyBehaviors' => fn ($q) => $q->orderBy('value')])
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id'])
            ->map(fn ($level) => [
                'id' => $level->id,
                'value' => $level->value,
                'value_en' => $level->value_en,
                'value_id' => $level->value_id,
                'key_behaviors' => $level->keyBehaviors->map(fn ($kb) => [
                    'id' => $kb->id,
                    'value' => $kb->value,
                    'value_en' => $kb->value_en,
                    'value_id' => $kb->value_id,
                ])->values(),
            ]);

        $hierarchy = $this->orgHierarchyData();

        return Inertia::render('Idp/MasterImplementation', [
            'implementations' => $implementations,
            'competencyTypes' => $this->competencyTypesData(),
            'competencies' => $competencies,
            'proficiencyLevels' => $proficiencyLevels,
            'grades' => $this->gradeOptions(),
            // Dynamic org-scope hierarchy (all guarded reads off kpncorp).
            'businessUnits' => $hierarchy['businessUnits'],
            'jobFamiliesByBu' => $hierarchy['jobFamiliesByBu'],
            'functionsByBu' => $hierarchy['functionsByBu'],
            'positionsByBuFunction' => $hierarchy['positionsByBuFunction'],
        ]);
    }

    /**
     * Proficiency level management — a standalone master list (name + bilingual
     * description), on its own page under IDP settings.
     */
    public function proficiencyLevel(): Response
    {
        $proficiencyLevels = DevelopmentPlanMaster::where('type', 'proficiency_level')
            ->with(['keyBehaviors' => fn ($q) => $q->orderBy('value')])
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'description_en', 'description_id'])
            ->map(fn ($level) => [
                'id' => $level->id,
                'value' => $level->value,
                'value_en' => $level->value_en,
                'value_id' => $level->value_id,
                'description_en' => $level->description_en,
                'description_id' => $level->description_id,
                'key_behaviors' => $level->keyBehaviors->map(fn ($kb) => [
                    'id' => $kb->id,
                    'value' => $kb->value,
                    'value_en' => $kb->value_en,
                    'value_id' => $kb->value_id,
                    'description_en' => $kb->description_en,
                    'description_id' => $kb->description_id,
                    'proficiency_level_id' => $kb->proficiency_level_id,
                ])->values(),
            ]);

        return Inertia::render('Idp/ProficiencyLevel', [
            'proficiencyLevels' => $proficiencyLevels,
        ]);
    }

    /**
     * Competency types (name + bilingual description), each carrying how many
     * competencies reference it so the UI can guard deletes.
     */
    private function competencyTypesData(): Collection
    {
        $typeUsage = DevelopmentPlanMaster::where('type', 'competency_name')
            ->whereNotNull('competency_type_id')
            ->selectRaw('competency_type_id, COUNT(*) as total')
            ->groupBy('competency_type_id')
            ->pluck('total', 'competency_type_id');

        return DevelopmentPlanMaster::where('type', 'competency_type')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'description_en', 'description_id'])
            ->map(fn ($ct) => [
                'id' => $ct->id,
                'value' => $ct->value,
                'value_en' => $ct->value_en,
                'value_id' => $ct->value_id,
                'description_en' => $ct->description_en,
                'description_id' => $ct->description_id,
                'competencies_count' => (int) ($typeUsage[$ct->id] ?? 0),
            ]);
    }

    /**
     * Whether the given competency_type master is the catch-all "Others" type.
     * A program on this type free-types its competencies + proficiency level
     * instead of picking them from the masters.
     */
    private function isOthersCompetencyType(?int $id): bool
    {
        if ($id === null) {
            return false;
        }

        $value = DevelopmentPlanMaster::where('type', 'competency_type')
            ->whereKey($id)
            ->value('value');

        return in_array(strtolower(trim((string) $value)), ['others', 'other', 'lainnya'], true);
    }

    /**
     * Grades a development program can be scoped to — the distinct employee
     * `job_level` values, matching how the rest of the app treats "grade".
     *
     * @return array<int, string>
     */
    private function gradeOptions(): array
    {
        try {
            return Employee::whereNotNull('job_level')
                ->distinct()->orderBy('job_level')
                ->pluck('job_level')->filter()->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The dynamic org-scope hierarchy sourced from kpncorp, shaped so the form
     * can cascade purely client-side (business unit → job family / function →
     * position):
     *
     *  - businessUnits         — the union of every business-unit grouping value
     *    across the source tables (employees, departments, designations).
     *  - jobFamiliesByBu       — bu ⇒ distinct employee `company_name`.
     *  - functionsByBu         — bu ⇒ distinct `departments.department_name`.
     *  - positionsByBuFunction — bu ⇒ function ⇒ distinct
     *    `designations.designation_name`.
     *
     * The business unit is the employee `group_company` (matched to
     * `departments`/`designations.parent_company_id`). Everything is read
     * defensively so an unreachable kpncorp never 500s the screen.
     *
     * @return array{businessUnits: list<string>, jobFamiliesByBu: array<string, list<string>>, functionsByBu: array<string, list<string>>, positionsByBuFunction: array<string, array<string, list<string>>>}
     */
    private function orgHierarchyData(): array
    {
        $clean = fn ($v) => trim((string) $v);
        $isReal = fn ($v) => $v !== '' && $v !== '-';

        // bu ⇒ [company_name] from the employee master.
        $jobFamiliesByBu = [];
        try {
            Employee::query()
                ->select('group_company', 'company_name')
                ->whereNotNull('group_company')
                ->whereNotNull('company_name')
                ->distinct()
                ->orderBy('company_name')
                ->get()
                ->each(function ($row) use (&$jobFamiliesByBu, $clean, $isReal) {
                    $bu = $clean($row->group_company);
                    $family = $clean($row->company_name);
                    if ($isReal($bu) && $isReal($family)) {
                        $jobFamiliesByBu[$bu][$family] = true;
                    }
                });
        } catch (\Throwable) {
        }

        // bu ⇒ [department_name] from the corporate departments table.
        $functionsByBu = [];
        try {
            DB::connection('kpncorp')->table('departments')
                ->select('parent_company_id', 'department_name')
                ->where('status', 'Active')
                ->whereNotNull('parent_company_id')
                ->whereNotNull('department_name')
                ->distinct()
                ->orderBy('department_name')
                ->get()
                ->each(function ($row) use (&$functionsByBu, $clean, $isReal) {
                    $bu = $clean($row->parent_company_id);
                    $fn = $clean($row->department_name);
                    if ($isReal($bu) && $isReal($fn)) {
                        $functionsByBu[$bu][$fn] = true;
                    }
                });
        } catch (\Throwable) {
        }

        // bu ⇒ function ⇒ [designation_name] from the corporate designations table.
        $positionsByBuFunction = [];
        try {
            DB::connection('kpncorp')->table('designations')
                ->select('parent_company_id', 'department_name', 'designation_name')
                ->where('status', 'Active')
                ->whereNotNull('parent_company_id')
                ->whereNotNull('department_name')
                ->whereNotNull('designation_name')
                ->distinct()
                ->orderBy('designation_name')
                ->get()
                ->each(function ($row) use (&$positionsByBuFunction, $clean, $isReal) {
                    $bu = $clean($row->parent_company_id);
                    $fn = $clean($row->department_name);
                    $pos = $clean($row->designation_name);
                    if ($isReal($bu) && $isReal($fn) && $isReal($pos)) {
                        $positionsByBuFunction[$bu][$fn][$pos] = true;
                    }
                });
        } catch (\Throwable) {
        }

        // Flatten the de-dup maps to ordered lists.
        $toList = fn (array $m) => collect(array_keys($m))->sort()->values()->all();

        $jobFamiliesByBu = collect($jobFamiliesByBu)->map($toList)->all();
        $functionsByBu = collect($functionsByBu)->map($toList)->all();
        $positionsByBuFunction = collect($positionsByBuFunction)
            ->map(fn ($byFn) => collect($byFn)->map($toList)->all())
            ->all();

        // The business-unit list is the union of every grouping value seen.
        $businessUnits = collect([
            ...array_keys($jobFamiliesByBu),
            ...array_keys($functionsByBu),
            ...array_keys($positionsByBuFunction),
        ])->unique()->sort()->values()->all();

        return [
            'businessUnits' => $businessUnits,
            'jobFamiliesByBu' => $jobFamiliesByBu,
            'functionsByBu' => $functionsByBu,
            'positionsByBuFunction' => $positionsByBuFunction,
        ];
    }

    /**
     * Packages with per-package roll-ups (model count + total weighting) plus
     * the active package id. Shared by the settings + development-model screens.
     *
     * @return array{0: Collection, 1: int|null}
     */
    private function packagesData(): array
    {
        $packageRollup = DevelopmentModel::selectRaw(
            'development_model_package_id, COUNT(*) as models_count, COALESCE(SUM(percentage), 0) as total_percentage'
        )->groupBy('development_model_package_id')->get()->keyBy('development_model_package_id');

        $activePackageId = DevelopmentModelPackage::active()?->id;

        $packages = DevelopmentModelPackage::orderByDesc('start_date')->orderByDesc('id')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'start_date' => $p->start_date?->toDateString(),
                'end_date' => $p->end_date?->toDateString(),
                'is_current' => $p->is_current,
                'is_active' => $p->id === $activePackageId,
                'models_count' => (int) ($packageRollup[$p->id]->models_count ?? 0),
                'total_percentage' => (int) ($packageRollup[$p->id]->total_percentage ?? 0),
            ]);

        return [$packages, $activePackageId];
    }

    /**
     * Weighted development models with their program / plan usage counts.
     */
    private function developmentModelsData(): Collection
    {
        return DevelopmentModel::orderByDesc('percentage')->orderBy('name')
            ->withCount(['developmentPrograms', 'individualDevelopmentPlans'])
            ->get();
    }

    // --- Development models ---

    public function storeModel(StoreDevelopmentModelRequest $request): RedirectResponse
    {
        $data = $request->validated();
        // Keep the canonical `name` (used for grouping/ordering) in step with
        // the English name.
        $data['name'] = $data['name_en'];

        DevelopmentModel::create($data);

        return back()->with('success', 'Development model added successfully.');
    }

    public function updateModel(UpdateDevelopmentModelRequest $request, DevelopmentModel $developmentModel): RedirectResponse
    {
        $data = $request->validated();

        // Optionally reassign this model's plans/programs to another, then delete.
        if (! empty($data['replace_with'])) {
            IndividualDevelopmentPlan::where('development_model_id', $developmentModel->id)
                ->update(['development_model_id' => $data['replace_with']]);
            DevelopmentPlanMaster::where('development_model_id', $developmentModel->id)
                ->update(['development_model_id' => $data['replace_with']]);
            $developmentModel->delete();

            return back()->with('success', "'{$developmentModel->name}' was replaced successfully.");
        }

        $developmentModel->update([
            'name' => $data['name_en'],
            'name_en' => $data['name_en'],
            'name_id' => $data['name_id'] ?? null,
            'percentage' => $data['percentage'],
            'description_en' => $data['description_en'] ?? null,
            'description_id' => $data['description_id'] ?? null,
        ]);

        return back()->with('success', 'Development model updated successfully.');
    }

    public function destroyModel(DevelopmentModel $developmentModel): RedirectResponse
    {
        if ($developmentModel->developmentPrograms()->exists()) {
            return back()->with('error', 'Cannot delete: this model is assigned to development programs.');
        }
        if ($developmentModel->individualDevelopmentPlans()->exists()) {
            return back()->with('error', 'Cannot delete: this model is used in employee IDPs.');
        }

        $developmentModel->delete();

        return back()->with('success', 'Development model deleted successfully.');
    }

    // --- Development model packages (period-scoped model bundles) ---

    public function storePackage(StoreDevelopmentModelPackageRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $package = DevelopmentModelPackage::create([
            'name' => $data['name'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'is_current' => $data['is_current'] ?? false,
        ]);

        // Only one package may be pinned as current.
        if ($package->is_current) {
            DevelopmentModelPackage::where('id', '!=', $package->id)->update(['is_current' => false]);
        }

        return back()->with('success', 'Package added successfully.');
    }

    public function updatePackage(UpdateDevelopmentModelPackageRequest $request, DevelopmentModelPackage $developmentModelPackage): RedirectResponse
    {
        $data = $request->validated();

        $developmentModelPackage->update([
            'name' => $data['name'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'is_current' => $data['is_current'] ?? false,
        ]);

        if ($developmentModelPackage->is_current) {
            DevelopmentModelPackage::where('id', '!=', $developmentModelPackage->id)->update(['is_current' => false]);
        }

        return back()->with('success', 'Package updated successfully.');
    }

    public function destroyPackage(DevelopmentModelPackage $developmentModelPackage): RedirectResponse
    {
        // The active/current package drives new development plans — it can't be
        // removed while it's in effect (either resolved active by date, or
        // manually pinned as current).
        if ($developmentModelPackage->is_current
            || $developmentModelPackage->id === DevelopmentModelPackage::active()?->id) {
            return back()->with('error', 'Cannot delete: this is the active package.');
        }

        // Block deleting a package whose models are still referenced by IDP
        // (development plan) data.
        $modelIds = $developmentModelPackage->developmentModels()->pluck('id');

        if ($modelIds->isNotEmpty() && IndividualDevelopmentPlan::whereIn('development_model_id', $modelIds)->exists()) {
            return back()->with('error', 'Cannot delete: this package has models used in development plans.');
        }

        if ($modelIds->isNotEmpty() && DevelopmentPlanMaster::whereIn('development_model_id', $modelIds)->exists()) {
            return back()->with('error', 'Cannot delete: this package has models assigned to development programs.');
        }

        // Soft delete the package and cascade to its (unused) models so they
        // don't linger without a package. Both are recoverable.
        if ($modelIds->isNotEmpty()) {
            DevelopmentModel::whereIn('id', $modelIds)->delete();
        }

        $developmentModelPackage->delete();

        return back()->with('success', 'Package deleted successfully.');
    }

    // --- Master data (competency_name / development_program / review_tools) ---

    public function storeMaster(Request $request): RedirectResponse
    {
        $data = $this->validateMaster($request);

        $isCompetency = $data['type'] === 'competency_name';
        // A key behavior belongs to a proficiency level via proficiency_level_id.
        $isKeyBehavior = $data['type'] === 'key_behavior';
        // A development program carries a competency type + proficiency level
        // (which scope its competency picker) plus a grade scope.
        $isProgram = $data['type'] === 'development_program';
        // "Others" programs free-type their competencies + proficiency level.
        $isOthers = $isProgram && $this->isOthersCompetencyType($data['competency_type_id'] ?? null);
        // Competency, competency type, proficiency level and key behavior all
        // carry a bilingual description.
        $hasDescription = in_array($data['type'], ['competency_name', 'competency_type', 'proficiency_level', 'key_behavior'], true);

        // A competency now pins several proficiency levels + key behaviors.
        $sel = $isCompetency ? $this->competencyProficiencySelection($data) : null;

        // A program is scoped to any number of grades.
        $grades = $isProgram ? $this->programGrades($data) : [];

        $master = DevelopmentPlanMaster::create([
            'type' => $data['type'],
            // Canonical `value` (grouping/ordering) tracks the English name.
            'value' => $data['value_en'],
            'value_en' => $data['value_en'],
            'value_id' => $data['value_id'] ?? null,
            'description_en' => $hasDescription ? ($data['description_en'] ?? null) : null,
            'description_id' => $hasDescription ? ($data['description_id'] ?? null) : null,
            'development_model_id' => $data['development_model_id'] ?? null,
            'competency_type_id' => ($isCompetency || $isProgram) ? ($data['competency_type_id'] ?? null) : null,
            // A picked proficiency level applies to a competency / key behavior,
            // and to a program only when it maps to real competencies (not "Others").
            // For a competency the singular column mirrors the first of its array.
            'proficiency_level_id' => $isCompetency
                ? $sel['proficiency_level_id']
                : (($isKeyBehavior || ($isProgram && ! $isOthers)) ? ($data['proficiency_level_id'] ?? null) : null),
            // A pinned key behavior only makes sense when a level is chosen.
            'key_behavior_id' => $isCompetency ? $sel['key_behavior_id'] : null,
            // The full multi-selection (competency only).
            'proficiency_level_ids' => $isCompetency ? $sel['proficiency_level_ids'] : null,
            'key_behavior_ids' => $isCompetency ? $sel['key_behavior_ids'] : null,
            // Free-typed competencies / proficiency — an "Others" program only.
            'custom_competency' => $isOthers ? ($data['custom_competency'] ?? null) : null,
            'custom_proficiency_level' => $isOthers ? ($data['custom_proficiency_level'] ?? null) : null,
            // Corporate scope — a program only. The singular column mirrors the
            // first of the array for anything that reads one value.
            'grades' => $isProgram ? $grades : null,
            'grade' => $isProgram ? ($grades[0] ?? null) : null,
            'related_program' => $isCompetency
                ? array_map('strval', $request->input('related_programs', []))
                : null,
        ]);

        if ($data['type'] === 'development_program') {
            // "Others" programs link no master competencies (they free-type instead).
            $this->linkProgramToCompetencies(
                $master->id,
                $isOthers ? [] : $request->input('related_competencies', []),
            );
        }

        return back()->with('success', 'Master data added successfully.');
    }

    public function updateMaster(Request $request, DevelopmentPlanMaster $master): RedirectResponse
    {
        $data = $this->validateMaster($request, $master);

        $oldValue = $master->value;
        $master->value = $data['value_en'];
        $master->value_en = $data['value_en'];
        $master->value_id = $data['value_id'] ?? null;
        $master->development_model_id = $data['development_model_id'] ?? null;

        if (in_array($master->type, ['competency_name', 'competency_type', 'proficiency_level', 'key_behavior'], true)) {
            $master->description_en = $data['description_en'] ?? null;
            $master->description_id = $data['description_id'] ?? null;
        }

        // A key behavior stays parented to its proficiency level (allow moving it).
        if ($master->type === 'key_behavior') {
            $master->proficiency_level_id = $data['proficiency_level_id'] ?? $master->proficiency_level_id;
        }

        if ($master->type === 'competency_name') {
            $master->competency_type_id = $data['competency_type_id'] ?? null;

            // Several proficiency levels + key behaviors; the singular columns
            // mirror the first of each array for the screens that read one value.
            $sel = $this->competencyProficiencySelection($data);
            $master->proficiency_level_ids = $sel['proficiency_level_ids'];
            $master->key_behavior_ids = $sel['key_behavior_ids'];
            $master->proficiency_level_id = $sel['proficiency_level_id'];
            $master->key_behavior_id = $sel['key_behavior_id'];

            // Only touch the program links when the form actually sent them,
            // so editing a competency's name/description never wipes links
            // that are now managed from the program side.
            if ($request->has('related_programs')) {
                $master->related_program = array_map('strval', $request->input('related_programs', []));
            }
        }

        // A development program carries its competency type + proficiency level
        // (scoping its competency picker) and a grade scope.
        // "Others" programs free-type their competencies + proficiency instead.
        $programIsOthers = false;
        if ($master->type === 'development_program') {
            $programIsOthers = $this->isOthersCompetencyType($data['competency_type_id'] ?? null);

            $master->competency_type_id = $data['competency_type_id'] ?? null;
            $master->proficiency_level_id = $programIsOthers ? null : ($data['proficiency_level_id'] ?? null);
            $master->custom_competency = $programIsOthers ? ($data['custom_competency'] ?? null) : null;
            $master->custom_proficiency_level = $programIsOthers ? ($data['custom_proficiency_level'] ?? null) : null;
            $grades = $this->programGrades($data);
            $master->grades = $grades;
            $master->grade = $grades[0] ?? null;
        }

        $master->save();

        if ($master->type === 'development_program') {
            // "Others" programs link no master competencies (they free-type instead).
            $this->syncProgramCompetencies(
                $master->id,
                $programIsOthers ? [] : $request->input('related_competencies', []),
            );
        }

        // Keep existing IDP rows in step with a renamed master value — but only
        // for master types that map to an actual column on individual_development_plans.
        // (competency_type / proficiency_level / key_behavior have no IDP column.)
        $idpColumns = ['competency_name', 'competency_type', 'development_program', 'review_tools'];
        if ($oldValue !== $master->value && in_array($master->type, $idpColumns, true)) {
            IndividualDevelopmentPlan::where($master->type, $oldValue)
                ->update([$master->type => $master->value]);
        }

        return back()->with('success', 'Master data updated successfully.');
    }

    public function destroyMaster(DevelopmentPlanMaster $master): RedirectResponse
    {
        // A competency type isn't referenced in IDP rows (no such column); it
        // is only referenced by competencies via competency_type_id.
        if ($master->type === 'competency_type') {
            $inUse = DevelopmentPlanMaster::where('type', 'competency_name')
                ->where('competency_type_id', $master->id)
                ->exists();

            if ($inUse) {
                return back()->with('error', "Cannot delete '{$master->value}': it is assigned to a competency.");
            }

            $master->delete();

            return back()->with('success', 'Master data deleted successfully.');
        }

        // A key behavior has no IDP column and nothing references it; delete freely.
        if ($master->type === 'key_behavior') {
            $master->delete();

            return back()->with('success', 'Master data deleted successfully.');
        }

        // A proficiency level has no IDP column; it is only referenced by
        // competencies (via proficiency_level_id) and owns key behaviors.
        if ($master->type === 'proficiency_level') {
            $inUse = DevelopmentPlanMaster::where('type', 'competency_name')
                ->where('proficiency_level_id', $master->id)
                ->exists();

            if ($inUse) {
                return back()->with('error', "Cannot delete '{$master->value}': it is assigned to a competency.");
            }

            $hasKeyBehaviors = DevelopmentPlanMaster::where('type', 'key_behavior')
                ->where('proficiency_level_id', $master->id)
                ->exists();

            if ($hasKeyBehaviors) {
                return back()->with('error', "Cannot delete '{$master->value}': remove its key behaviors first.");
            }

            $master->delete();

            return back()->with('success', 'Master data deleted successfully.');
        }

        if (IndividualDevelopmentPlan::where($master->type, $master->value)->exists()) {
            return back()->with('error', "Cannot delete '{$master->value}': it is used in an IDP.");
        }

        if ($master->type === 'development_program') {
            $this->unlinkProgramFromCompetencies($master->id);
        }

        $master->delete();

        return back()->with('success', 'Master data deleted successfully.');
    }

    // --- Master implementation (competency → org scope) ---

    public function storeImplementation(Request $request): RedirectResponse
    {
        $data = $this->validateImplementation($request);

        DevelopmentPlanMaster::create([
            'type' => 'implementation',
            // Canonical `value` tracks the implemented competency's name so the
            // row is never empty (it has no name of its own).
            'value' => DevelopmentPlanMaster::whereKey($data['competency_name_id'])->value('value'),
            'competency_type_id' => $data['competency_type_id'],
            'competency_name_id' => $data['competency_name_id'],
            'proficiency_level_id' => $data['proficiency_level_id'] ?? null,
            'grade' => $data['grade'] ?? null,
            'business_unit' => $data['business_unit'] ?? null,
            'job_family' => $data['job_family'] ?? null,
            'function_name' => $data['function_name'] ?? null,
            'position' => $data['position'] ?? null,
        ]);

        return back()->with('success', 'Master implementation added successfully.');
    }

    public function updateImplementation(Request $request, DevelopmentPlanMaster $master): RedirectResponse
    {
        abort_unless($master->type === 'implementation', 404);

        $data = $this->validateImplementation($request, $master);

        $master->update([
            'value' => DevelopmentPlanMaster::whereKey($data['competency_name_id'])->value('value'),
            'competency_type_id' => $data['competency_type_id'],
            'competency_name_id' => $data['competency_name_id'],
            'proficiency_level_id' => $data['proficiency_level_id'] ?? null,
            'grade' => $data['grade'] ?? null,
            'business_unit' => $data['business_unit'] ?? null,
            'job_family' => $data['job_family'] ?? null,
            'function_name' => $data['function_name'] ?? null,
            'position' => $data['position'] ?? null,
        ]);

        return back()->with('success', 'Master implementation updated successfully.');
    }

    public function destroyImplementation(DevelopmentPlanMaster $master): RedirectResponse
    {
        abort_unless($master->type === 'implementation', 404);

        $master->delete();

        return back()->with('success', 'Master implementation deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateImplementation(Request $request, ?DevelopmentPlanMaster $master = null): array
    {
        $data = $request->validate([
            'competency_type_id' => [
                'required', 'integer',
                Rule::exists('development_plan_masters', 'id')->where('type', 'competency_type'),
            ],
            'competency_name_id' => [
                'required', 'integer',
                Rule::exists('development_plan_masters', 'id')->where('type', 'competency_name'),
            ],
            'proficiency_level_id' => [
                'nullable', 'integer',
                Rule::exists('development_plan_masters', 'id')->where('type', 'proficiency_level'),
            ],
            'grade' => ['nullable', 'string', 'max:255'],
            'business_unit' => ['nullable', 'string', 'max:255'],
            'job_family' => ['nullable', 'string', 'max:255'],
            'function_name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
        ]);

        // The chosen competency must belong to the chosen competency type.
        $validator = validator([], []);
        $competency = DevelopmentPlanMaster::where('type', 'competency_name')
            ->whereKey($data['competency_name_id'])
            ->first(['id', 'competency_type_id']);

        if ($competency && (int) $competency->competency_type_id !== (int) $data['competency_type_id']) {
            $validator->errors()->add('competency_name_id', 'The selected competency does not belong to the chosen competency type.');
            throw new ValidationException($validator);
        }

        // Guard against a duplicate implementation for the same competency + scope.
        $duplicate = DevelopmentPlanMaster::where('type', 'implementation')
            ->when($master, fn ($q) => $q->whereKeyNot($master->id))
            ->where('competency_name_id', $data['competency_name_id'])
            ->where('grade', $data['grade'] ?? null)
            ->where('business_unit', $data['business_unit'] ?? null)
            ->where('job_family', $data['job_family'] ?? null)
            ->where('function_name', $data['function_name'] ?? null)
            ->where('position', $data['position'] ?? null)
            ->exists();

        if ($duplicate) {
            $validator = validator([], []);
            $validator->errors()->add('competency_name_id', 'A master implementation with the same competency and scope already exists.');
            throw new ValidationException($validator);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateMaster(Request $request, ?DevelopmentPlanMaster $master = null): array
    {
        $type = $master?->type ?? $request->input('type');

        // Key behaviors are scoped to their parent proficiency level, so their
        // names only need to be unique within that level (two levels may share
        // an identically named behavior).
        $uniqueValue = Rule::unique('development_plan_masters', 'value')
            ->where('type', $type)
            ->where('development_model_id', $request->input('development_model_id'))
            ->ignore($master?->id);

        if ($type === 'key_behavior') {
            $uniqueValue->where('proficiency_level_id', $request->input('proficiency_level_id'));
        }

        return $request->validate([
            'type' => [$master ? 'sometimes' : 'required', 'string', 'in:competency_name,competency_type,proficiency_level,key_behavior,development_program,review_tools'],
            'value_en' => [
                'required', 'string', 'max:255',
                $uniqueValue,
            ],
            'value_id' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string'],
            'description_id' => ['nullable', 'string'],
            'development_model_id' => ['nullable', 'integer', 'exists:development_models,id'],
            'competency_type_id' => [
                // A development program must be classified by a competency type.
                $type === 'development_program' ? 'required' : 'nullable',
                'integer',
                Rule::exists('development_plan_masters', 'id')->where('type', 'competency_type'),
            ],
            'proficiency_level_id' => [
                // Required as the parent when creating/editing a key behavior;
                // optional as the chosen level on a competency.
                $type === 'key_behavior' ? 'required' : 'nullable',
                'integer',
                Rule::exists('development_plan_masters', 'id')->where('type', 'proficiency_level'),
            ],
            'key_behavior_id' => [
                'nullable', 'integer',
                Rule::exists('development_plan_masters', 'id')->where('type', 'key_behavior'),
                // A pinned key behavior must belong to the chosen proficiency level.
                function (string $attribute, $value, \Closure $fail) use ($request) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $belongs = DevelopmentPlanMaster::where('id', $value)
                        ->where('type', 'key_behavior')
                        ->where('proficiency_level_id', $request->input('proficiency_level_id'))
                        ->exists();
                    if (! $belongs) {
                        $fail('The selected key behavior does not belong to the chosen proficiency level.');
                    }
                },
            ],
            // A competency's multi-selected proficiency levels + key behaviors.
            'proficiency_level_ids' => ['nullable', 'array'],
            'proficiency_level_ids.*' => [
                'integer',
                Rule::exists('development_plan_masters', 'id')->where('type', 'proficiency_level'),
            ],
            'key_behavior_ids' => ['nullable', 'array'],
            'key_behavior_ids.*' => [
                'integer',
                Rule::exists('development_plan_masters', 'id')->where('type', 'key_behavior'),
            ],
            'related_programs' => ['nullable', 'array'],
            'related_competencies' => ['nullable', 'array'],
            // Free-typed competencies / proficiency for an "Others" program.
            'custom_competency' => ['nullable', 'string', 'max:2000'],
            'custom_proficiency_level' => ['nullable', 'string', 'max:255'],
            // Development-program corporate scope — any number of grades,
            // each stored as the raw string.
            'grades' => ['nullable', 'array'],
            'grades.*' => ['string', 'max:255'],
        ]);
    }

    /**
     * The distinct, non-empty grades a development program is scoped to, in the
     * order they were picked. An empty list means the program applies to every
     * grade.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function programGrades(array $data): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($g) => trim((string) $g), $data['grades'] ?? []),
            fn ($g) => $g !== '',
        )));
    }

    /**
     * Normalize a competency's proficiency-level + key-behavior selections into
     * the stored shape: the multi-value array columns plus the legacy singular
     * columns kept in step (first element) for the screens that still read one
     * value. Key behaviors are dropped unless they belong to one of the chosen
     * levels, and cleared entirely when no level is chosen.
     *
     * @param  array<string, mixed>  $data
     * @return array{proficiency_level_ids: array<int>, key_behavior_ids: array<int>, proficiency_level_id: int|null, key_behavior_id: int|null}
     */
    private function competencyProficiencySelection(array $data): array
    {
        $levelIds = array_values(array_unique(array_map('intval', $data['proficiency_level_ids'] ?? [])));

        $behaviorIds = array_values(array_unique(array_map('intval', $data['key_behavior_ids'] ?? [])));

        if (empty($levelIds)) {
            $behaviorIds = [];
        } elseif (! empty($behaviorIds)) {
            // Keep only behaviors that actually belong to one of the chosen levels.
            $valid = DevelopmentPlanMaster::where('type', 'key_behavior')
                ->whereIn('id', $behaviorIds)
                ->whereIn('proficiency_level_id', $levelIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $behaviorIds = array_values(array_filter($behaviorIds, fn ($id) => in_array($id, $valid, true)));
        }

        return [
            'proficiency_level_ids' => $levelIds,
            'key_behavior_ids' => $behaviorIds,
            'proficiency_level_id' => $levelIds[0] ?? null,
            'key_behavior_id' => $behaviorIds[0] ?? null,
        ];
    }

    /**
     * Add a program id to the given competencies' related_program lists.
     */
    private function linkProgramToCompetencies(int $programId, array $competencyIds): void
    {
        $programIdStr = (string) $programId;

        DevelopmentPlanMaster::whereIn('id', $competencyIds)
            ->where('type', 'competency_name')
            ->get()
            ->each(function ($comp) use ($programIdStr) {
                $current = $comp->related_program ?? [];
                if (! in_array($programIdStr, $current, true)) {
                    $current[] = $programIdStr;
                    $comp->update(['related_program' => $current]);
                }
            });
    }

    /**
     * Make exactly the given competencies link to this program.
     */
    private function syncProgramCompetencies(int $programId, array $competencyIds): void
    {
        $programIdStr = (string) $programId;

        DevelopmentPlanMaster::where('type', 'competency_name')->get()->each(function ($comp) use ($programIdStr, $competencyIds) {
            $current = $comp->related_program ?? [];
            $isLinked = in_array($programIdStr, $current, true);
            $shouldLink = in_array($comp->id, $competencyIds);

            if ($shouldLink && ! $isLinked) {
                $current[] = $programIdStr;
                $comp->update(['related_program' => $current]);
            } elseif (! $shouldLink && $isLinked) {
                $comp->update(['related_program' => array_values(array_diff($current, [$programIdStr]))]);
            }
        });
    }

    private function unlinkProgramFromCompetencies(int $programId): void
    {
        $programIdStr = (string) $programId;

        DevelopmentPlanMaster::where('type', 'competency_name')
            ->whereJsonContains('related_program', $programIdStr)
            ->get()
            ->each(function ($comp) use ($programIdStr) {
                $comp->update([
                    'related_program' => array_values(array_diff($comp->related_program ?? [], [$programIdStr])),
                ]);
            });
    }
}
