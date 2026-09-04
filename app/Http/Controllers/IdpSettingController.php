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
use App\Models\Training;
use App\Services\IdpMasterService;
use App\Services\MasterStatusAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IdpSettingController extends Controller
{
    public function __construct(
        private readonly IdpMasterService $masters,
        private readonly MasterStatusAudit $audit,
    ) {}

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
            // The master-implementation map drives the program form's
            // proficiency + grade pickers: a program may only target a level
            // some implementation maps its competencies to, and only the
            // grades that mapping covers.
            'implementations' => $this->implementationScopes(),
            // A program's name is either typed or taken from Master Training,
            // so the form needs the catalogue to pick from. The flag rides on
            // `option()`; the form keeps an inactive training listed only while
            // a program already points at it.
            'trainings' => $this->options(Training::orderBy('name_en')->get()),
            // Corporate scope option list for the program form (grades), read
            // defensively so an unreachable kpncorp never breaks the screen.
            'grades' => $this->gradeOptions(),
            'developmentPrograms' => $programs->map(fn (DevelopmentProgram $p) => $this->option($p) + [
                'development_model_id' => $p->development_model_id,
                'model_name' => $p->developmentModel?->name,
                'competency_type_id' => $p->competency_type_id,
                // Null when the name was typed rather than taken from a training.
                'training_id' => $p->training_id,
                'proficiency_level_id' => $p->proficiency_level_id,
                // Free-typed proficiency level (an "Others" program).
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
        $trainings = Training::with(['proficiencyLevels:id', 'businessUnits', 'workLocations'])
            ->orderBy('name_en')
            ->get()
            ->map(fn (Training $t) => $this->option($t) + [
                'description_en' => $t->description_en,
                'description_id' => $t->description_id,
                // What the training builds, and who it is offered to. Every
                // part of the scope but the competency is a list.
                'competency_type_id' => $t->competency_type_id,
                'competency_id' => $t->competency_id,
                'proficiency_level_ids' => $t->proficiencyLevels->pluck('id')->all(),
                'business_units' => $t->businessUnits->pluck('business_unit')->all(),
                'work_locations' => $t->workLocations->pluck('work_location')->all(),
            ]);

        $locations = $this->workLocationData();

        return Inertia::render('Idp/MasterTraining', [
            'trainings' => $trainings,
            'competencyTypes' => $this->competencyTypesData(),
            // Competencies carry their type so the form can cascade
            // (type -> competency); the effective period rides on `option()`
            // and is what keeps expired competencies off the list.
            'competencies' => Competency::orderBy('name_en')->get()
                ->map(fn (Competency $c) => $this->option($c) + [
                    'competency_type_id' => $c->competency_type_id,
                ]),
            // Levels carry the type they are filed under, so the form can
            // scope them to the chosen competency type (a level with no type
            // is global and fits every type). Their effective period rides on
            // `option()` — a level is judged on its own dates here, not through
            // the competency.
            'proficiencyLevels' => ProficiencyLevel::orderBy('name_en')->get()
                ->map(fn (ProficiencyLevel $pl) => $this->option($pl) + [
                    'competency_type_id' => $pl->competency_type_id,
                ]),
            'businessUnits' => $locations['businessUnits'],
            'workLocationsByBu' => $locations['byBusinessUnit'],
        ]);
    }

    /**
     * Work locations from the corporate `locations` table, grouped by the
     * business unit that owns them (`locations.company_name`, the same grouping
     * the employee master calls `group_company`). The location itself is the
     * `area` — the named site, e.g. "Head Office - Jakarta".
     *
     * The business-unit list is the union of the two sources, so a unit that
     * has employees but no location rows (or the reverse) still shows up; its
     * location dropdown is then simply empty. Both reads are guarded, so an
     * unreachable kpncorp leaves the screen working with no options rather than
     * 500ing.
     *
     * @return array{businessUnits: list<string>, byBusinessUnit: array<string, list<string>>}
     */
    private function workLocationData(): array
    {
        $clean = fn ($v) => trim((string) $v);
        $isReal = fn ($v) => $v !== '' && $v !== '-';

        $byBusinessUnit = [];
        try {
            DB::connection('kpncorp')->table('locations')
                ->select('company_name', 'area')
                ->whereNotNull('company_name')
                ->whereNotNull('area')
                ->distinct()
                ->orderBy('area')
                ->get()
                ->each(function ($row) use (&$byBusinessUnit, $clean, $isReal) {
                    $bu = $clean($row->company_name);
                    $area = $clean($row->area);
                    if ($isReal($bu) && $isReal($area)) {
                        $byBusinessUnit[$bu][$area] = true;
                    }
                });
        } catch (\Throwable) {
        }

        $byBusinessUnit = collect($byBusinessUnit)
            ->map(fn (array $areas) => collect(array_keys($areas))->sort()->values()->all())
            ->all();

        // Business units the employee master knows about, so the list matches
        // what the rest of the app calls a business unit.
        $employeeUnits = [];
        try {
            $employeeUnits = Employee::whereNotNull('group_company')
                ->distinct()->pluck('group_company')
                ->map($clean)->filter($isReal)->values()->all();
        } catch (\Throwable) {
        }

        $businessUnits = collect([...array_keys($byBusinessUnit), ...$employeeUnits])
            ->unique()->sort()->values()->all();

        return ['businessUnits' => $businessUnits, 'byBusinessUnit' => $byBusinessUnit];
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
            // Levels carry the type they are filed under so the competency form
            // can scope its level options to the chosen competency type
            // (a level with no type is global and fits every type).
            'proficiencyLevels' => ProficiencyLevel::orderBy('name_en')->get()
                ->map(fn (ProficiencyLevel $pl) => $this->option($pl) + [
                    'competency_type_id' => $pl->competency_type_id,
                ]),
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
     * proficiency levels) to a corporate org scope: grades plus a dynamic
     * business-unit hierarchy (business unit -> job family / function ->
     * position).
     */
    public function masterImplementation(): Response
    {
        $implementations = CompetencyImplementation::with(['proficiencyLevels:id', 'grades', 'businessUnits'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (CompetencyImplementation $i) => [
                'id' => $i->id,
                'is_active' => $i->is_active,
                'competency_type_id' => $i->competency_type_id,
                'competency_id' => $i->competency_id,
                'proficiency_level_ids' => $i->proficiencyLevels->pluck('id')->all(),
                'grades' => $i->grades->pluck('grade')->values(),
                'business_units' => $i->businessUnits->pluck('business_unit')->values(),
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
     * Proficiency level management (name + competency type + bilingual
     * description) together with the key behaviors each level owns.
     */
    public function proficiencyLevel(): Response
    {
        $proficiencyLevels = ProficiencyLevel::with(['keyBehaviors' => fn ($q) => $q->orderBy('name_en')])
            ->orderBy('name_en')
            ->get()
            ->map(fn (ProficiencyLevel $level) => $this->option($level) + [
                'description_en' => $level->description_en,
                'description_id' => $level->description_id,
                // The type this level is filed under; null = global.
                'competency_type_id' => $level->competency_type_id,
                'key_behaviors' => $level->keyBehaviors->map(fn (KeyBehavior $kb) => $this->option($kb) + [
                    'description_en' => $kb->description_en,
                    'description_id' => $kb->description_id,
                    'proficiency_level_id' => $kb->proficiency_level_id,
                ])->values(),
            ]);

        return Inertia::render('Idp/ProficiencyLevel', [
            'proficiencyLevels' => $proficiencyLevels,
            'competencyTypes' => $this->competencyTypesData(),
        ]);
    }

    /**
     * A submitted id as an int, or null when the form sent it empty.
     */
    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * A submitted list of ids as unique ints.
     *
     * @return array<int, int>
     */
    private function intList(mixed $values): array
    {
        return array_values(array_unique(array_map('intval', (array) $values)));
    }

    /**
     * A submitted list of raw strings, trimmed, blanks dropped, de-duplicated.
     *
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        return collect((array) $values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
        $option = [
            'id' => $row->id,
            'value' => $row->name_en,
            'value_en' => $row->name_en,
            'value_id' => $row->name_id,
        ];

        // Masters that can be switched off carry the flag on every payload, so
        // each screen can badge the row and keep inactive ones out of its
        // pickers.
        if (array_key_exists('is_active', $row->getAttributes())) {
            $option['is_active'] = (bool) $row->is_active;
        }

        return $option;
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
     * The master-implementation map, flattened to what the development-program
     * form needs: which proficiency levels a competency is implemented at, and
     * which grades each of those mappings covers.
     *
     * An empty `grades` list means the mapping covers every grade, exactly as
     * it does on the implementation screen itself.
     *
     * @param  Builder|null  $query  narrow the rows (defaults to all of them)
     * @return Collection<int, array<string, mixed>>
     */
    private function implementationScopes(?Builder $query = null): Collection
    {
        return ($query ?? CompetencyImplementation::query())
            ->with(['proficiencyLevels:id', 'grades'])
            ->get()
            ->map(fn (CompetencyImplementation $i) => [
                'competency_id' => $i->competency_id,
                'proficiency_level_ids' => $i->proficiencyLevels->pluck('id')->all(),
                'grades' => $i->grades->pluck('grade')->values()->all(),
            ])
            ->values();
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

    /**
     * Activate / deactivate one master from its list screen. Deactivating keeps
     * the row and everything referencing it — it only takes the master out of
     * the pickers for new work.
     */
    public function toggleMasterActive(Request $request, MasterDataType $type, int $id): RedirectResponse
    {
        if (! $type->hasActiveState()) {
            return back()->with('error', 'This master data type cannot be activated or deactivated.');
        }

        $master = $type->query()->findOrFail($id);
        $active = $request->boolean('is_active');

        $this->masters->setActive($type, $master, $active);

        return back()->with(
            'success',
            $active ? 'Master data activated successfully.' : 'Master data deactivated successfully.'
        );
    }

    /**
     * The activate / deactivate trail for one master, newest first. Read from
     * the audit log on disk, not from the database.
     */
    public function masterStatusHistory(MasterDataType $type, int $id): JsonResponse
    {
        return response()->json([
            'history' => $this->masters->statusHistory($type, $id),
        ]);
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
        $isCompetency = $type === MasterDataType::CompetencyName;
        $isTraining = $type === MasterDataType::Training;

        // A program whose name comes from Master Training carries the training
        // rather than the text. Resolving it up front means the name rules -
        // required, length, uniqueness per model - all police the real value.
        if ($isProgram) {
            $this->applyTrainingName($request);
        }

        $uniqueName = Rule::unique($type->table(), 'name_en')->ignore($master?->id);

        if ($type === MasterDataType::KeyBehavior) {
            // A behavior name only has to be unique inside its own level.
            $uniqueName->where('proficiency_level_id', $request->input('proficiency_level_id'));
        } elseif ($type === MasterDataType::ProficiencyLevel) {
            // Levels are named per competency type, so "Level 1" may exist under
            // several types. The composite unique index cannot police untyped
            // rows (MySQL keeps NULLs distinct), so this rule carries that case.
            $uniqueName->where('competency_type_id', $this->intOrNull($request->input('competency_type_id')));
        } elseif ($isProgram) {
            // Programs are named per development model.
            $uniqueName->where('development_model_id', $request->input('development_model_id'));
        }

        $data = $request->validate([
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
            // Active/inactive (competencies / proficiency levels / review
            // tools). Absent means active.
            'is_active' => ['nullable', 'boolean'],
            'development_model_id' => ['nullable', 'integer', 'exists:development_models,id'],
            // Set when a program takes its name from the Master Training
            // catalogue; null when the name is typed.
            'training_id' => ['nullable', 'integer', 'exists:trainings,id'],
            'competency_type_id' => [
                // Development programs, competencies and trainings are all
                // classified by a competency type; the competency's type is
                // what scopes the proficiency levels it may pin, and a
                // training's type is what scopes the competency it builds.
                $isProgram || $isCompetency || $isTraining ? 'required' : 'nullable',
                'integer', 'exists:competency_types,id',
            ],
            // The single competency a training builds.
            'competency_id' => [
                $isTraining ? 'required' : 'nullable',
                'integer', 'exists:competencies,id',
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
            // A development program develops exactly one competency. It stays
            // a list on the wire because the link is a pivot — a competency
            // reaches many programs, and the competency screen edits that side.
            'related_competencies' => ['nullable', 'array', 'max:1'],
            'related_competencies.*' => ['integer', 'exists:competencies,id'],
            // Free-typed proficiency level for an "Others" program.
            'custom_proficiency_level' => ['nullable', 'string', 'max:255'],
            // Development-program corporate scope: any number of grades, each
            // stored as the raw string.
            'grades' => ['nullable', 'array'],
            'grades.*' => ['string', 'max:255'],
            // A training's corporate scope: any number of business units and,
            // under them, any number of work locations. Both are raw kpncorp
            // strings. A location belongs to a unit, so it can't stand alone.
            'business_units' => ['nullable', 'array', 'required_with:work_locations'],
            'business_units.*' => ['string', 'max:255'],
            'work_locations' => ['nullable', 'array'],
            'work_locations.*' => ['string', 'max:255'],
        ]);

        // A competency's proficiency levels have to come from its own type. A
        // level with no type is global, so it fits under any of them.
        if ($isCompetency && ! empty($data['proficiency_level_ids'])) {
            $foreign = ProficiencyLevel::whereIn('id', $data['proficiency_level_ids'])
                ->whereNotNull('competency_type_id')
                ->where('competency_type_id', '!=', $data['competency_type_id'])
                ->exists();

            if ($foreign) {
                $this->fail(
                    'proficiency_level_ids',
                    'The selected proficiency levels must belong to the chosen competency type.'
                );
            }

            $this->assertLevelsActiveForCompetency($data, $master);
        }

        // A program reaches its masters through the implementation map, so its
        // competencies have to still be usable and its level + grades have to
        // come from an implementation of those competencies.
        if ($isProgram) {
            $this->assertProgramSelectionsUsable($data, $master);
        }

        if ($isTraining) {
            $this->assertTrainingSelectionsUsable($data, $master);
        }

        return $data;
    }

    /**
     * A master training has no effective period of its own — like a master
     * implementation, it simply applies from now on — so everything it points
     * at has to be usable from now on too:
     *
     *  - the competency has to belong to the chosen competency type, and its
     *    effective period must not have ended;
     *  - every proficiency level has to be filed under the chosen competency
     *    type (or be untyped, and so global), and none of their periods may
     *    have ended — levels are judged on their own dates here, not through
     *    the competency;
     *  - every work location has to belong to one of the chosen business
     *    units.
     *
     * A master that is merely *scheduled* stays selectable — the training will
     * still be there when its period opens; only an expired one is refused.
     *
     * What the training already stores is exempt on every count: once a master
     * lapses, editing an unrelated field — a description, a location — must not
     * become impossible.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertTrainingSelectionsUsable(array $data, ?Model $master): void
    {
        $competencyId = $this->intOrNull($data['competency_id'] ?? null);
        $competency = $competencyId === null ? null : Competency::find($competencyId);

        if ($competency !== null) {
            if ((int) $competency->competency_type_id !== (int) $data['competency_type_id']) {
                $this->fail(
                    'competency_id',
                    'The selected competency does not belong to the chosen competency type.'
                );
            }

            $unchanged = (int) $master?->competency_id === $competency->id;

            if (! $unchanged && ! $competency->is_active) {
                $this->fail(
                    'competency_id',
                    "Cannot use '{$competency->name_en}': it is inactive."
                );
            }
        }

        $this->assertTrainingLevelsUsable(
            $this->intList($data['proficiency_level_ids'] ?? []),
            $master instanceof Training
                ? $master->proficiencyLevels()->pluck('proficiency_levels.id')->all()
                : [],
            (int) $data['competency_type_id'],
        );

        $this->assertWorkLocationInBusinessUnit($data, $master);
    }

    /**
     * The proficiency levels a training targets have to be usable by it.
     *
     * The competency type is checked on every submitted level with no
     * exemption: a level's type is stable, so a mismatch means the pick has to
     * be redone. The effective period exempts what the training already stores,
     * since a level lapsing must not make an unrelated edit impossible.
     *
     * @param  array<int, int>  $levelIds
     * @param  array<int, int>  $exempt  levels already stored on the training
     */
    private function assertTrainingLevelsUsable(array $levelIds, array $exempt, int $typeId): void
    {
        if ($levelIds === []) {
            return;
        }

        // A level filed under another type can't serve this training. An
        // untyped level is global, so it fits under any type.
        $foreign = ProficiencyLevel::whereIn('id', $levelIds)
            ->whereNotNull('competency_type_id')
            ->where('competency_type_id', '!=', $typeId)
            ->orderBy('name_en')
            ->pluck('name_en');

        if ($foreign->isNotEmpty()) {
            $this->fail(
                'proficiency_level_ids',
                'These proficiency levels do not belong to the chosen competency type: '
                    .$foreign->implode(', ').'.'
            );
        }

        $this->assertLevelsActive($levelIds, $exempt);
    }

    /**
     * Every work location has to be a corporate location of one of the chosen
     * business units — the union across them, since a training offered in
     * several units may run at a site of any of them.
     *
     * The check is skipped when none of those units have known locations — that
     * is either units the `locations` table doesn't cover or an unreachable
     * kpncorp, and neither should block a save. Locations the training already
     * stores are left alone, so a site that has since been renamed corporately
     * doesn't make the row uneditable.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertWorkLocationInBusinessUnit(array $data, ?Model $master): void
    {
        $locations = $this->stringList($data['work_locations'] ?? []);
        $businessUnits = $this->stringList($data['business_units'] ?? []);

        if ($locations === [] || $businessUnits === []) {
            return;
        }

        $stored = $master instanceof Training
            ? $master->workLocations()->pluck('work_location')->all()
            : [];

        $added = array_values(array_diff($locations, $stored));

        if ($added === []) {
            return;
        }

        $byBusinessUnit = $this->workLocationData()['byBusinessUnit'];

        $known = collect($businessUnits)
            ->flatMap(fn (string $unit) => $byBusinessUnit[$unit] ?? [])
            ->unique()
            ->all();

        if ($known === []) {
            return;
        }

        $rejected = array_values(array_diff($added, $known));

        if ($rejected !== []) {
            $this->fail(
                'work_locations',
                'These are not work locations of the selected business units: '
                    .implode(', ', $rejected).'.'
            );
        }
    }

    /**
     * Copy the chosen training's name onto the request, so a training-sourced
     * program is validated and stored exactly like a typed one. The program
     * keeps its own `name_en` / `name_id`: IDP rows name a program verbatim and
     * every list reads those columns, so `training_id` records where the name
     * came from rather than replacing it.
     *
     * The copy happens on every save, so re-saving a program picks up a
     * training that has since been renamed.
     */
    private function applyTrainingName(Request $request): void
    {
        $training = Training::find($this->intOrNull($request->input('training_id')));

        if ($training === null) {
            // Nothing chosen (or it has since gone): the typed name stands.
            $request->merge(['training_id' => null]);

            return;
        }

        $request->merge([
            'value_en' => $training->name_en,
            'value_id' => $training->name_id,
        ]);
    }

    /**
     * A development program is scoped through the master implementations of the
     * competencies it develops: the proficiency level it targets has to be one
     * an implementation maps those competencies to, and its grades have to fall
     * inside that mapping's coverage. Inactive competencies can no longer be
     * developed at all.
     *
     * This applies to every competency type, the catch-all "Others" included —
     * a program on it picks a real competency master too. What "Others" still
     * free-types is its proficiency level, which arrives in
     * `custom_proficiency_level` and so leaves nothing here to police.
     *
     * Only what the form *adds* is checked. Whatever the program already stores
     * stays valid, so editing an unrelated field never fails because a
     * competency has since been deactivated or an implementation has since
     * narrowed — the same exemption the competency form gives its pinned levels.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertProgramSelectionsUsable(array $data, ?Model $master): void
    {
        $program = $master instanceof DevelopmentProgram ? $master : null;

        $competencyIds = array_values(array_unique(array_map(
            'intval',
            $data['related_competencies'] ?? []
        )));

        $this->assertCompetenciesActive(
            $competencyIds,
            $program?->competencies()->pluck('competencies.id')->all() ?? [],
        );

        $levelId = $this->intOrNull($data['proficiency_level_id'] ?? null);

        if ($levelId === null) {
            return;
        }

        $scopes = $competencyIds === []
            ? collect()
            : $this->implementationScopes(
                CompetencyImplementation::whereIn('competency_id', $competencyIds)
            );

        $covers = fn (array $scope) => in_array($levelId, $scope['proficiency_level_ids'], true);

        if ($levelId !== $program?->proficiency_level_id && ! $scopes->contains($covers)) {
            $this->fail(
                'proficiency_level_id',
                'The selected proficiency level is not implemented for the chosen competency.'
            );
        }

        $this->assertGradesImplemented(
            $data['grades'] ?? [],
            $program?->grades()->pluck('grade')->all() ?? [],
            $scopes->filter($covers),
        );
    }

    /**
     * Reject competencies whose effective period has already ended — nothing
     * new can develop them. A competency that has not started yet is fine: it
     * is scheduled, not gone. Ids in `$exempt` are already on the program.
     *
     * A program develops a single competency, but the check stays list-shaped:
     * the link behind it is a pivot, and one day it may carry more again.
     *
     * @param  array<int, int>  $competencyIds
     * @param  array<int, int>  $exempt
     */
    private function assertCompetenciesActive(array $competencyIds, array $exempt): void
    {
        $added = array_values(array_diff($competencyIds, $exempt));

        if ($added === []) {
            return;
        }

        $inactive = Competency::whereIn('id', $added)
            ->inactive()
            ->orderBy('name_en')
            ->pluck('name_en');

        if ($inactive->isNotEmpty()) {
            $this->fail(
                'related_competencies',
                ($inactive->count() === 1 ? 'This competency is' : 'These competencies are')
                    .' inactive: '.$inactive->implode(', ').'.'
            );
        }
    }

    /**
     * Reject grades the implementation map does not cover for the chosen level.
     * With no mapping at all, or one that lists no grades of its own (meaning
     * every grade), there is nothing to police. Grades the program already
     * stores are exempt.
     *
     * @param  array<int, string>  $grades
     * @param  array<int, string>  $exempt
     * @param  Collection<int, array<string, mixed>>  $scopes  mappings covering the level
     */
    private function assertGradesImplemented(array $grades, array $exempt, Collection $scopes): void
    {
        if ($scopes->isEmpty() || $scopes->contains(fn (array $s) => $s['grades'] === [])) {
            return;
        }

        $covered = $scopes->flatMap(fn (array $s) => $s['grades'])->unique()->all();

        $rejected = array_values(array_diff(
            array_map('strval', $grades),
            $exempt,
            $covered,
        ));

        if ($rejected !== []) {
            $this->fail(
                'grades',
                'These grades are not covered by the master implementation of the selected proficiency level: '
                    .implode(', ', $rejected).'.'
            );
        }
    }

    /**
     * A competency may only pin proficiency levels that are still switched on.
     *
     * Levels the competency is *already* linked to are exempt: once a level is
     * deactivated, an unrelated edit to the competency must not become
     * impossible — the same reason a deactivated master stays resolvable on the
     * IDP rows that already name it.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertLevelsActiveForCompetency(array $data, ?Model $master): void
    {
        $this->assertLevelsActive(
            $data['proficiency_level_ids'],
            $master instanceof Competency
                ? $master->proficiencyLevels()->pluck('proficiency_levels.id')->all()
                : [],
        );
    }

    /**
     * Reject inactive proficiency levels, naming them. Ids in `$exempt` are
     * already stored on the row being edited and are left alone.
     *
     * @param  array<int, int>  $levelIds
     * @param  array<int, int>  $exempt
     */
    private function assertLevelsActive(array $levelIds, array $exempt): void
    {
        $added = array_values(array_diff($levelIds, $exempt));

        if ($added === []) {
            return;
        }

        $rejected = ProficiencyLevel::whereIn('id', $added)
            ->inactive()
            ->orderBy('name_en')
            ->pluck('name_en');

        if ($rejected->isNotEmpty()) {
            $this->fail(
                'proficiency_level_ids',
                'These proficiency levels are inactive: '.$rejected->implode(', ').'.'
            );
        }
    }

    // --- Master implementation (competency -> org scope) ---

    public function storeImplementation(Request $request): RedirectResponse
    {
        $data = $this->validateImplementation($request);

        $implementation = CompetencyImplementation::create($data);
        $this->syncImplementationScope($implementation, $data);

        // A mapping created switched off is a transition worth recording; one
        // created active is just the default.
        if (! $implementation->is_active) {
            $this->recordImplementationStatus($implementation, false);
        }

        return back()->with('success', 'Master implementation added successfully.');
    }

    public function updateImplementation(Request $request, CompetencyImplementation $implementation): RedirectResponse
    {
        $data = $this->validateImplementation($request, $implementation);

        $wasActive = (bool) $implementation->is_active;

        $implementation->update($data);
        $this->syncImplementationScope($implementation, $data);

        // Only the transition is logged, so re-saving an unchanged form never
        // adds an entry.
        if ($wasActive !== (bool) $implementation->is_active) {
            $this->recordImplementationStatus($implementation, (bool) $implementation->is_active);
        }

        return back()->with('success', 'Master implementation updated successfully.');
    }

    /**
     * Activate / deactivate one mapping from the list screen. Deactivating
     * keeps the row — it only stops the mapping applying from now on.
     */
    public function toggleImplementationActive(
        Request $request,
        CompetencyImplementation $implementation,
    ): RedirectResponse {
        $active = $request->boolean('is_active');

        if ((bool) $implementation->is_active !== $active) {
            $implementation->forceFill(['is_active' => $active])->save();
            $this->recordImplementationStatus($implementation, $active);
        }

        return back()->with(
            'success',
            $active
                ? 'Master implementation activated successfully.'
                : 'Master implementation deactivated successfully.'
        );
    }

    /**
     * The activate / deactivate trail for one mapping, newest first. Read from
     * the audit log on disk, not from the database.
     */
    public function implementationStatusHistory(CompetencyImplementation $implementation): JsonResponse
    {
        return response()->json([
            'history' => $this->audit->for(MasterStatusAudit::IMPLEMENTATION, $implementation->id),
        ]);
    }

    public function destroyImplementation(CompetencyImplementation $implementation): RedirectResponse
    {
        $implementation->delete();

        return back()->with('success', 'Master implementation deleted successfully.');
    }

    /**
     * Replace the list-valued parts of a mapping's scope: its proficiency
     * levels, grades and business units.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncImplementationScope(CompetencyImplementation $implementation, array $data): void
    {
        $implementation->proficiencyLevels()->sync($data['proficiency_level_ids']);

        $this->replaceScopeValues($implementation->grades(), 'grade', $data['grades']);
        $this->replaceScopeValues(
            $implementation->businessUnits(),
            'business_unit',
            $data['business_units'],
        );
    }

    /**
     * Replace a child list of raw corporate strings with the submitted one.
     *
     * @param  list<string>  $values
     */
    private function replaceScopeValues(HasMany $relation, string $column, array $values): void
    {
        $relation->delete();

        if ($values !== []) {
            $relation->createMany(array_map(fn (string $value) => [$column => $value], $values));
        }
    }

    /**
     * Log a mapping's activation change. A mapping has no name of its own, so
     * the competency it maps is what the history shows.
     */
    private function recordImplementationStatus(CompetencyImplementation $implementation, bool $active): void
    {
        $this->audit->record(
            MasterStatusAudit::IMPLEMENTATION,
            $implementation->id,
            $implementation->competency?->name_en ?? "#{$implementation->id}",
            $active,
            Auth::user(),
        );
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
            // ...and scope to any number of grades (empty means every grade).
            'grades' => ['nullable', 'array'],
            'grades.*' => ['string', 'max:255'],
            // A mapping covers any number of business units; empty means it is
            // not narrowed to one.
            'business_units' => ['nullable', 'array'],
            'business_units.*' => ['string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'job_family' => ['nullable', 'string', 'max:255'],
            'function_name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
        ]);

        $data['proficiency_level_ids'] = array_values(array_unique(
            array_map('intval', $data['proficiency_level_ids'] ?? [])
        ));

        $data['grades'] = $this->stringList($data['grades'] ?? []);
        $data['business_units'] = $this->stringList($data['business_units'] ?? []);
        // Absent means active, so an older caller keeps creating live mappings.
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        // The chosen competency must belong to the chosen competency type.
        $competency = Competency::find($data['competency_id']);

        if ($competency && (int) $competency->competency_type_id !== (int) $data['competency_type_id']) {
            $this->fail('competency_id', 'The selected competency does not belong to the chosen competency type.');
        }

        // Guard against a duplicate implementation for the same competency +
        // scope. Grades are a list on the row, so they are not part of the key:
        // one row per competency + org scope carries every grade it covers.
        // Business units are a list too, and two rows that differ only by which
        // units they cover are genuinely different mappings — so the sets are
        // compared, rather than the row being keyed on a single unit.
        $wanted = $data['business_units'];
        sort($wanted);

        $duplicate = CompetencyImplementation::query()
            ->when($implementation, fn ($q) => $q->whereKeyNot($implementation->id))
            ->where('competency_id', $data['competency_id'])
            ->where('job_family', $data['job_family'] ?? null)
            ->where('function_name', $data['function_name'] ?? null)
            ->where('position', $data['position'] ?? null)
            ->with('businessUnits')
            ->get()
            ->contains(function (CompetencyImplementation $existing) use ($wanted) {
                $units = $existing->businessUnits->pluck('business_unit')->all();
                sort($units);

                return $units === $wanted;
            });

        if ($duplicate) {
            $this->fail('competency_id', 'A master implementation with the same competency and scope already exists.');
        }

        $this->assertImplementationActive($data, $competency, $implementation);

        return $data;
    }

    /**
     * An implementation may only map masters that are switched on: an inactive
     * competency, or an inactive proficiency level, would produce a mapping
     * that applies to nobody.
     *
     * What the implementation already stores is exempt, so once a competency or
     * level is deactivated, editing an unrelated field (a grade, a position) on
     * an existing row still works.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertImplementationActive(
        array $data,
        ?Competency $competency,
        ?CompetencyImplementation $implementation,
    ): void {
        if ($competency === null) {
            return;
        }

        $unchanged = (int) $implementation?->competency_id === (int) $competency->id;

        if (! $competency->is_active && ! $unchanged) {
            $this->fail(
                'competency_id',
                "Cannot use '{$competency->name_en}': it is inactive."
            );
        }

        $this->assertLevelsActive(
            $data['proficiency_level_ids'],
            $implementation?->proficiencyLevels()->pluck('proficiency_levels.id')->all() ?? [],
        );
    }

    private function fail(string $field, string $message): never
    {
        $validator = validator([], []);
        $validator->errors()->add($field, $message);

        throw new ValidationException($validator);
    }
}
