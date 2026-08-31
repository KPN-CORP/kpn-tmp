<?php

namespace App\Http\Controllers;

use App\Enums\MasterDataType;
use App\Http\Requests\StoreDevelopmentModelPackageRequest;
use App\Http\Requests\StoreDevelopmentModelRequest;
use App\Http\Requests\UpdateDevelopmentModelPackageRequest;
use App\Http\Requests\UpdateDevelopmentModelRequest;
use App\Models\Competency;
use App\Models\CompetencyImplementation;
use App\Models\CompetencyType;
use App\Models\DevelopmentModel;
use App\Models\DevelopmentModelPackage;
use App\Models\DevelopmentProgram;
use App\Models\Employee;
use App\Models\IndividualDevelopmentPlan;
use App\Models\KeyBehavior;
use App\Models\ProficiencyLevel;
use App\Services\IdpMasterService;
use Illuminate\Database\Eloquent\Model;
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
    public function __construct(private readonly IdpMasterService $masters) {}

    public function index(): Response
    {
        $programs = DevelopmentProgram::with(['developmentModel:id,name,name_en,name_id,percentage', 'grades'])
            ->orderBy('name_en')
            ->get();

        $competencies = Competency::with(['proficiencyLevels:id', 'developmentPrograms:id,name_en'])
            ->orderBy('name_en')
            ->get()
            ->map(fn (Competency $c) => $this->option($c) + [
                'description_en' => $c->description_en,
                'description_id' => $c->description_id,
                'competency_type_id' => $c->competency_type_id,
                // The program form derives its proficiency options from the
                // levels of the competencies picked for the program.
                'proficiency_level_ids' => $c->proficiencyLevels->pluck('id')->all(),
                'related_program' => $c->developmentPrograms->pluck('id')->values(),
                'linked_programs' => $c->developmentPrograms->pluck('name_en')->values(),
            ]);

        [$packages, $activePackageId] = $this->packagesData();

        return Inertia::render('Idp/Settings', [
            'packages' => $packages,
            'activePackageId' => $activePackageId,
            'developmentModels' => $this->developmentModelsData(),
            'competencies' => $competencies,
            'competencyTypes' => $this->competencyTypesData(),
            'proficiencyLevels' => $this->options(ProficiencyLevel::orderBy('name_en')->get()),
            // Corporate scope option list for the program form (grades), read
            // defensively so an unreachable kpncorp never breaks the screen.
            'grades' => $this->gradeOptions(),
            'developmentPrograms' => $programs->map(fn (DevelopmentProgram $p) => $this->option($p) + [
                'development_model_id' => $p->development_model_id,
                'model_name' => $p->developmentModel?->name,
                'competency_type_id' => $p->competency_type_id,
                'proficiency_level_id' => $p->proficiency_level_id,
                // Free-typed competencies / proficiency (an "Others" program).
                'custom_competency' => $p->custom_competency,
                'custom_proficiency_level' => $p->custom_proficiency_level,
                'grades' => $p->grades->pluck('grade')->values(),
            ]),
        ]);
    }

    /**
     * Review tools management: a standalone master list (bilingual name), on its
     * own page under IDP settings.
     */
    public function reviewTools(): Response
    {
        return Inertia::render('Idp/ReviewTools', [
            'reviewTools' => $this->options(MasterDataType::ReviewTools->query()->orderBy('name_en')->get()),
        ]);
    }

    /**
     * Master Training: a standalone master list of trainings (bilingual name +
     * description), on its own page under IDP settings.
     */
    public function masterTraining(): Response
    {
        return Inertia::render('Idp/MasterTraining', [
            'trainings' => MasterDataType::Training->query()->orderBy('name_en')->get()
                ->map(fn (Model $t) => $this->option($t) + [
                    'description_en' => $t->description_en,
                    'description_id' => $t->description_id,
                ]),
        ]);
    }

    /**
     * Development model management (packages + weighted models), on its own page.
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
     * Competency management (competencies + competency types), on its own page.
     */
    public function competency(): Response
    {
        $competencies = Competency::with(['proficiencyLevels:id', 'keyBehaviors:id'])
            ->orderBy('name_en')
            ->get()
            ->map(fn (Competency $c) => $this->option($c) + [
                'description_en' => $c->description_en,
                'description_id' => $c->description_id,
                'competency_type_id' => $c->competency_type_id,
                'proficiency_level_ids' => $c->proficiencyLevels->pluck('id')->all(),
                'key_behavior_ids' => $c->keyBehaviors->pluck('id')->all(),
            ]);

        return Inertia::render('Idp/Competency', [
            'competencies' => $competencies,
            'competencyTypes' => $this->competencyTypesData(),
            'proficiencyLevels' => $this->options(ProficiencyLevel::orderBy('name_en')->get()),
            // Key behaviors carry their owning proficiency_level_id so the
            // competency form can scope the behavior dropdown to the chosen level.
            'keyBehaviors' => KeyBehavior::orderBy('name_en')->get()
                ->map(fn (KeyBehavior $kb) => $this->option($kb) + [
                    'proficiency_level_id' => $kb->proficiency_level_id,
                ]),
        ]);
    }

    /**
     * Master Implementation: maps a single competency (with one or more
     * proficiency levels) to a corporate org scope: grade plus a dynamic
     * business-unit hierarchy (business unit -> job family / function ->
     * position).
     */
    public function masterImplementation(): Response
    {
        $implementations = CompetencyImplementation::with('proficiencyLevels:id')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CompetencyImplementation $i) => [
                'id' => $i->id,
                'competency_type_id' => $i->competency_type_id,
                'competency_id' => $i->competency_id,
                'proficiency_level_ids' => $i->proficiencyLevels->pluck('id')->all(),
                'grade' => $i->grade,
                'business_unit' => $i->business_unit,
                'job_family' => $i->job_family,
                'function_name' => $i->function_name,
                'position' => $i->position,
            ]);

        // Competencies carry their type + available proficiency levels so the
        // form can cascade (type -> competency -> proficiency levels).
        $competencies = Competency::with('proficiencyLevels:id')
            ->orderBy('name_en')
            ->get()
            ->map(fn (Competency $c) => $this->option($c) + [
                'competency_type_id' => $c->competency_type_id,
                'proficiency_level_ids' => $c->proficiencyLevels->pluck('id')->all(),
            ]);

        $hierarchy = $this->orgHierarchyData();

        return Inertia::render('Idp/MasterImplementation', [
            'implementations' => $implementations,
            'competencyTypes' => $this->competencyTypesData(),
            'competencies' => $competencies,
            'proficiencyLevels' => $this->options(ProficiencyLevel::orderBy('name_en')->get()),
            'grades' => $this->gradeOptions(),
            // Dynamic org-scope hierarchy (all guarded reads off kpncorp).
            'businessUnits' => $hierarchy['businessUnits'],
            'jobFamiliesByBu' => $hierarchy['jobFamiliesByBu'],
            'functionsByBu' => $hierarchy['functionsByBu'],
            'positionsByBuFunction' => $hierarchy['positionsByBuFunction'],
        ]);
    }

    /**
     * Proficiency level management (name + bilingual description) together with
     * the key behaviors each level owns.
     */
    public function proficiencyLevel(): Response
    {
        $proficiencyLevels = ProficiencyLevel::with(['keyBehaviors' => fn ($q) => $q->orderBy('name_en')])
            ->orderBy('name_en')
            ->get()
            ->map(fn (ProficiencyLevel $level) => $this->option($level) + [
                'description_en' => $level->description_en,
                'description_id' => $level->description_id,
                'key_behaviors' => $level->keyBehaviors->map(fn (KeyBehavior $kb) => $this->option($kb) + [
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
     * Shape a master row into the option payload the settings screens expect.
     *
     * `value` is the canonical name and `value_en`/`value_id` are the localized
     * display names. The old table stored those separately and let them drift;
     * `name_en` is now the single source for both `value` and `value_en`.
     *
     * @return array<string, mixed>
     */
    private function option(Model $row): array
    {
        return [
            'id' => $row->id,
            'value' => $row->name_en,
            'value_en' => $row->name_en,
            'value_id' => $row->name_id,
        ];
    }

    /**
     * @param  Collection<int, Model>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function options(Collection $rows): Collection
    {
        return $rows->map(fn (Model $row) => $this->option($row));
    }

    /**
     * Competency types, each carrying how many competencies reference it so the
     * UI can guard deletes.
     */
    private function competencyTypesData(): Collection
    {
        return CompetencyType::withCount('competencies')
            ->orderBy('name_en')
            ->get()
            ->map(fn (CompetencyType $ct) => $this->option($ct) + [
                'description_en' => $ct->description_en,
                'description_id' => $ct->description_id,
                'competencies_count' => (int) $ct->competencies_count,
            ]);
    }

    /**
     * Grades a development program can be scoped to: the distinct employee
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
     * can cascade purely client-side (business unit -> job family / function ->
     * position):
     *
     *  - businessUnits         the union of every business-unit grouping value
     *    across the source tables (employees, departments, designations).
     *  - jobFamiliesByBu       bu => distinct employee `company_name`.
     *  - functionsByBu         bu => distinct `departments.department_name`.
     *  - positionsByBuFunction bu => function => distinct
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

        // bu => [company_name] from the employee master.
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

        // bu => [department_name] from the corporate departments table.
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

        // bu => function => [designation_name] from the corporate designations table.
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
            DevelopmentProgram::where('development_model_id', $developmentModel->id)
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
        // The active/current package drives new development plans, so it can't
        // be removed while it's in effect (either resolved active by date, or
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

        if ($modelIds->isNotEmpty() && DevelopmentProgram::whereIn('development_model_id', $modelIds)->exists()) {
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

    // --- Master data ---
    //
    // One set of endpoints for every kind of IDP master. The kind arrives as
    // the `type` field / path segment and resolves to a MasterDataType, which
    // picks the table to work on.

    public function storeMaster(Request $request): RedirectResponse
    {
        $type = MasterDataType::tryFrom((string) $request->input('type'));

        if ($type === null) {
            $this->fail('type', 'The selected master data type is invalid.');
        }

        $this->masters->create($type, $this->validateMaster($request, $type));

        return back()->with('success', 'Master data added successfully.');
    }

    public function updateMaster(Request $request, MasterDataType $type, int $id): RedirectResponse
    {
        $master = $type->query()->findOrFail($id);

        $this->masters->update(
            $type,
            $master,
            $this->validateMaster($request, $type, $master),
            array_keys($request->all()),
        );

        return back()->with('success', 'Master data updated successfully.');
    }

    public function destroyMaster(MasterDataType $type, int $id): RedirectResponse
    {
        $master = $type->query()->findOrFail($id);

        if ($blocker = $this->masters->deletionBlocker($type, $master)) {
            return back()->with('error', $blocker);
        }

        $this->masters->delete($type, $master);

        return back()->with('success', 'Master data deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateMaster(Request $request, MasterDataType $type, ?Model $master = null): array
    {
        $isProgram = $type === MasterDataType::DevelopmentProgram;

        $uniqueName = Rule::unique($type->table(), 'name_en')->ignore($master?->id);

        if ($type === MasterDataType::KeyBehavior) {
            // A behavior name only has to be unique inside its own level.
            $uniqueName->where('proficiency_level_id', $request->input('proficiency_level_id'));
        } elseif ($isProgram) {
            // Programs are named per development model.
            $uniqueName->where('development_model_id', $request->input('development_model_id'));
        }

        return $request->validate([
            'type' => [$master ? 'sometimes' : 'required', 'string', 'in:'.MasterDataType::validationList()],
            'value_en' => [
                'required', 'string',
                // Program "names" are activity descriptions and run long; every
                // other master is a short label.
                'max:'.($isProgram ? 1000 : 255),
                $uniqueName,
            ],
            'value_id' => ['nullable', 'string', 'max:'.($isProgram ? 1000 : 255)],
            'description_en' => ['nullable', 'string'],
            'description_id' => ['nullable', 'string'],
            'development_model_id' => ['nullable', 'integer', 'exists:development_models,id'],
            'competency_type_id' => [
                // A development program must be classified by a competency type.
                $isProgram ? 'required' : 'nullable',
                'integer', 'exists:competency_types,id',
            ],
            'proficiency_level_id' => [
                // Required as the owning level when creating/editing a key
                // behavior; optional as the chosen level on a program.
                $type === MasterDataType::KeyBehavior ? 'required' : 'nullable',
                'integer', 'exists:proficiency_levels,id',
            ],
            // A competency's multi-selected proficiency levels + key behaviors.
            'proficiency_level_ids' => ['nullable', 'array'],
            'proficiency_level_ids.*' => ['integer', 'exists:proficiency_levels,id'],
            'key_behavior_ids' => ['nullable', 'array'],
            'key_behavior_ids.*' => ['integer', 'exists:key_behaviors,id'],
            'related_programs' => ['nullable', 'array'],
            'related_programs.*' => ['integer', 'exists:development_programs,id'],
            'related_competencies' => ['nullable', 'array'],
            'related_competencies.*' => ['integer', 'exists:competencies,id'],
            // Free-typed competencies / proficiency for an "Others" program.
            'custom_competency' => ['nullable', 'string', 'max:2000'],
            'custom_proficiency_level' => ['nullable', 'string', 'max:255'],
            // Development-program corporate scope: any number of grades, each
            // stored as the raw string.
            'grades' => ['nullable', 'array'],
            'grades.*' => ['string', 'max:255'],
        ]);
    }

    // --- Master implementation (competency -> org scope) ---

    public function storeImplementation(Request $request): RedirectResponse
    {
        $data = $this->validateImplementation($request);

        $implementation = CompetencyImplementation::create($data);
        $implementation->proficiencyLevels()->sync($data['proficiency_level_ids']);

        return back()->with('success', 'Master implementation added successfully.');
    }

    public function updateImplementation(Request $request, CompetencyImplementation $implementation): RedirectResponse
    {
        $data = $this->validateImplementation($request, $implementation);

        $implementation->update($data);
        $implementation->proficiencyLevels()->sync($data['proficiency_level_ids']);

        return back()->with('success', 'Master implementation updated successfully.');
    }

    public function destroyImplementation(CompetencyImplementation $implementation): RedirectResponse
    {
        $implementation->delete();

        return back()->with('success', 'Master implementation deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateImplementation(Request $request, ?CompetencyImplementation $implementation = null): array
    {
        $data = $request->validate([
            'competency_type_id' => ['required', 'integer', 'exists:competency_types,id'],
            'competency_id' => ['required', 'integer', 'exists:competencies,id'],
            // An implementation can pin one or more proficiency levels.
            'proficiency_level_ids' => ['nullable', 'array'],
            'proficiency_level_ids.*' => ['integer', 'exists:proficiency_levels,id'],
            'grade' => ['nullable', 'string', 'max:255'],
            'business_unit' => ['nullable', 'string', 'max:255'],
            'job_family' => ['nullable', 'string', 'max:255'],
            'function_name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
        ]);

        $data['proficiency_level_ids'] = array_values(array_unique(
            array_map('intval', $data['proficiency_level_ids'] ?? [])
        ));

        // The chosen competency must belong to the chosen competency type.
        $competency = Competency::find($data['competency_id']);

        if ($competency && (int) $competency->competency_type_id !== (int) $data['competency_type_id']) {
            $this->fail('competency_id', 'The selected competency does not belong to the chosen competency type.');
        }

        // Guard against a duplicate implementation for the same competency + scope.
        $duplicate = CompetencyImplementation::query()
            ->when($implementation, fn ($q) => $q->whereKeyNot($implementation->id))
            ->where('competency_id', $data['competency_id'])
            ->where('grade', $data['grade'] ?? null)
            ->where('business_unit', $data['business_unit'] ?? null)
            ->where('job_family', $data['job_family'] ?? null)
            ->where('function_name', $data['function_name'] ?? null)
            ->where('position', $data['position'] ?? null)
            ->exists();

        if ($duplicate) {
            $this->fail('competency_id', 'A master implementation with the same competency and scope already exists.');
        }

        return $data;
    }

    private function fail(string $field, string $message): never
    {
        $validator = validator([], []);
        $validator->errors()->add($field, $message);

        throw new ValidationException($validator);
    }
}
