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
use App\Models\MasterBisnisunit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IdpSettingController extends Controller
{
    public function index(): Response
    {
        $programs = DevelopmentPlanMaster::where('type', 'development_program')
            ->with('developmentModel:id,name,name_en,name_id,percentage')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'development_model_id']);

        $programValues = $programs->keyBy('id');

        $competencies = DevelopmentPlanMaster::where('type', 'competency_name')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'description_en', 'description_id', 'related_program', 'competency_type_id'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'value' => $c->value,
                'value_en' => $c->value_en,
                'value_id' => $c->value_id,
                'description_en' => $c->description_en,
                'description_id' => $c->description_id,
                'competency_type_id' => $c->competency_type_id,
                'related_program' => collect($c->related_program ?? [])->map(fn ($id) => (int) $id)->values(),
                'linked_programs' => collect($c->related_program ?? [])
                    ->map(fn ($id) => $programValues->get($id)?->value)
                    ->filter()
                    ->values(),
            ]);

        $packages = $this->packagesData();

        return Inertia::render('Idp/Settings', [
            'packages' => $packages,
            // Default package for the program form's model dropdown: the first
            // package in effect today (packages are audience-scoped, but a
            // development program is a global master, so any active one seeds the
            // dropdown). Null when none are active.
            'activePackageId' => $packages->firstWhere('is_active', true)['id'] ?? null,
            'developmentModels' => $this->developmentModelsData(),
            'competencies' => $competencies,
            'developmentPrograms' => $programs->map(fn ($p) => [
                'id' => $p->id,
                'value' => $p->value,
                'value_en' => $p->value_en,
                'value_id' => $p->value_id,
                'development_model_id' => $p->development_model_id,
                'model_name' => $p->developmentModel?->name,
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
        return Inertia::render('Idp/DevelopmentModel', [
            'packages' => $this->packagesData(),
            'developmentModels' => $this->developmentModelsData(),
            'scopeOptions' => $this->scopeOptions(),
        ]);
    }

    /**
     * The audience options a package can be scoped to: corporate business units
     * (`master_bisnisunits.nama_bisnis`) and the grade levels present on
     * employees (`employees.job_level`). Both are read defensively so a downed
     * kpncorp connection yields empty lists rather than an error.
     *
     * @return array{businessUnits: list<string>, grades: list<string>}
     */
    private function scopeOptions(): array
    {
        try {
            $businessUnits = MasterBisnisunit::orderBy('nama_bisnis')
                ->pluck('nama_bisnis')->filter()->values()->all();
        } catch (\Throwable) {
            $businessUnits = [];
        }

        try {
            $grades = Employee::query()->distinct()->orderBy('job_level')
                ->pluck('job_level')->filter()->values()->all();
        } catch (\Throwable) {
            $grades = [];
        }

        return ['businessUnits' => $businessUnits, 'grades' => $grades];
    }

    /**
     * Competency management (competencies + competency types), split onto its
     * own page from the master-data settings screen.
     */
    public function competency(): Response
    {
        $competencies = DevelopmentPlanMaster::where('type', 'competency_name')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'description_en', 'description_id', 'competency_type_id', 'proficiency_level_id', 'key_behavior_id']);

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
     * Packages with per-package roll-ups (model count + total weighting), their
     * business-unit / grade audience, and whether each is in effect today.
     * Several packages can be in effect at once (scoping keeps their audiences
     * apart), so "active" is a per-row flag rather than a single id.
     */
    private function packagesData(): Collection
    {
        $packageRollup = DevelopmentModel::selectRaw(
            'development_model_package_id, COUNT(*) as models_count, COALESCE(SUM(percentage), 0) as total_percentage'
        )->groupBy('development_model_package_id')->get()->keyBy('development_model_package_id');

        return DevelopmentModelPackage::orderByDesc('start_date')->orderByDesc('id')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'business_units' => array_values($p->business_units ?? []),
                'grades' => array_values($p->grades ?? []),
                'start_date' => $p->start_date?->toDateString(),
                'end_date' => $p->end_date?->toDateString(),
                'is_current' => $p->is_current,
                'is_active' => $p->isInEffect(),
                'models_count' => (int) ($packageRollup[$p->id]->models_count ?? 0),
                'total_percentage' => (int) ($packageRollup[$p->id]->total_percentage ?? 0),
            ]);
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

        DevelopmentModelPackage::create([
            'name' => $data['name'],
            'business_units' => array_values($data['business_units']),
            'grades' => array_values($data['grades']),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'is_current' => $data['is_current'] ?? false,
        ]);

        return back()->with('success', 'Package added successfully.');
    }

    public function updatePackage(UpdateDevelopmentModelPackageRequest $request, DevelopmentModelPackage $developmentModelPackage): RedirectResponse
    {
        $data = $request->validated();

        $developmentModelPackage->update([
            'name' => $data['name'],
            'business_units' => array_values($data['business_units']),
            'grades' => array_values($data['grades']),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'is_current' => $data['is_current'] ?? false,
        ]);

        return back()->with('success', 'Package updated successfully.');
    }

    public function destroyPackage(DevelopmentModelPackage $developmentModelPackage): RedirectResponse
    {
        // A package that is in effect today drives new development plans for its
        // audience — it can't be removed while active (either its period covers
        // today, or it is manually pinned as current).
        if ($developmentModelPackage->isInEffect()) {
            return back()->with('error', 'Cannot delete: this package is currently active.');
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
        // Competency, competency type, proficiency level and key behavior all
        // carry a bilingual description.
        $hasDescription = in_array($data['type'], ['competency_name', 'competency_type', 'proficiency_level', 'key_behavior'], true);

        $master = DevelopmentPlanMaster::create([
            'type' => $data['type'],
            // Canonical `value` (grouping/ordering) tracks the English name.
            'value' => $data['value_en'],
            'value_en' => $data['value_en'],
            'value_id' => $data['value_id'] ?? null,
            'description_en' => $hasDescription ? ($data['description_en'] ?? null) : null,
            'description_id' => $hasDescription ? ($data['description_id'] ?? null) : null,
            'development_model_id' => $data['development_model_id'] ?? null,
            'competency_type_id' => $isCompetency ? ($data['competency_type_id'] ?? null) : null,
            'proficiency_level_id' => ($isCompetency || $isKeyBehavior) ? ($data['proficiency_level_id'] ?? null) : null,
            // A pinned key behavior only makes sense when a level is chosen.
            'key_behavior_id' => ($isCompetency && ! empty($data['proficiency_level_id']))
                ? ($data['key_behavior_id'] ?? null)
                : null,
            'related_program' => $isCompetency
                ? array_map('strval', $request->input('related_programs', []))
                : null,
        ]);

        if ($data['type'] === 'development_program') {
            $this->linkProgramToCompetencies($master->id, $request->input('related_competencies', []));
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
            $master->proficiency_level_id = $data['proficiency_level_id'] ?? null;
            // A pinned key behavior only makes sense when a level is chosen.
            $master->key_behavior_id = ! empty($data['proficiency_level_id'])
                ? ($data['key_behavior_id'] ?? null)
                : null;

            // Only touch the program links when the form actually sent them,
            // so editing a competency's name/description never wipes links
            // that are now managed from the program side.
            if ($request->has('related_programs')) {
                $master->related_program = array_map('strval', $request->input('related_programs', []));
            }
        }

        $master->save();

        if ($master->type === 'development_program') {
            $this->syncProgramCompetencies($master->id, $request->input('related_competencies', []));
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
                'nullable', 'integer',
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
            'related_programs' => ['nullable', 'array'],
            'related_competencies' => ['nullable', 'array'],
        ]);
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
