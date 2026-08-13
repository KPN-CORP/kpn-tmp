<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDevelopmentModelPackageRequest;
use App\Http\Requests\StoreDevelopmentModelRequest;
use App\Http\Requests\UpdateDevelopmentModelPackageRequest;
use App\Http\Requests\UpdateDevelopmentModelRequest;
use App\Models\DevelopmentModel;
use App\Models\DevelopmentModelPackage;
use App\Models\DevelopmentPlanMaster;
use App\Models\IndividualDevelopmentPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        // Competency types (name + bilingual description), each carrying how
        // many competencies reference it so the UI can guard deletes.
        $typeUsage = DevelopmentPlanMaster::where('type', 'competency_name')
            ->whereNotNull('competency_type_id')
            ->selectRaw('competency_type_id, COUNT(*) as total')
            ->groupBy('competency_type_id')
            ->pluck('total', 'competency_type_id');

        $competencyTypes = DevelopmentPlanMaster::where('type', 'competency_type')
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

        // Per-package roll-ups (model count + total weighting) so the UI can
        // show each package's balance without a query per package.
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

        return Inertia::render('Idp/Settings', [
            'competencyTypes' => $competencyTypes,
            'packages' => $packages,
            'activePackageId' => $activePackageId,
            'developmentModels' => DevelopmentModel::orderByDesc('percentage')->orderBy('name')
                ->withCount(['developmentPrograms', 'individualDevelopmentPlans'])
                ->get(),
            'competencies' => $competencies,
            'developmentPrograms' => $programs->map(fn ($p) => [
                'id' => $p->id,
                'value' => $p->value,
                'value_en' => $p->value_en,
                'value_id' => $p->value_id,
                'development_model_id' => $p->development_model_id,
                'model_name' => $p->developmentModel?->name,
            ]),
            'reviewTools' => DevelopmentPlanMaster::where('type', 'review_tools')
                ->orderBy('value')->get(['id', 'value', 'value_en', 'value_id']),
        ]);
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
        // Block deleting a package whose models are still referenced by IDP
        // plans (deleting the package cascades to its models).
        $modelIds = $developmentModelPackage->developmentModels()->pluck('id');

        if ($modelIds->isNotEmpty() && IndividualDevelopmentPlan::whereIn('development_model_id', $modelIds)->exists()) {
            return back()->with('error', 'Cannot delete: this package has models used in employee IDPs.');
        }

        if ($modelIds->isNotEmpty() && DevelopmentPlanMaster::whereIn('development_model_id', $modelIds)->exists()) {
            return back()->with('error', 'Cannot delete: this package has models assigned to development programs.');
        }

        $developmentModelPackage->delete();

        return back()->with('success', 'Package deleted successfully.');
    }

    // --- Master data (competency_name / development_program / review_tools) ---

    public function storeMaster(Request $request): RedirectResponse
    {
        $data = $this->validateMaster($request);

        $isCompetency = $data['type'] === 'competency_name';
        // Competency and competency type both carry a bilingual description.
        $hasDescription = in_array($data['type'], ['competency_name', 'competency_type'], true);

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

        if (in_array($master->type, ['competency_name', 'competency_type'], true)) {
            $master->description_en = $data['description_en'] ?? null;
            $master->description_id = $data['description_id'] ?? null;
        }

        if ($master->type === 'competency_name') {
            $master->competency_type_id = $data['competency_type_id'] ?? null;

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

        // Keep existing IDP rows in step with a renamed master value.
        if ($oldValue !== $master->value) {
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

        return $request->validate([
            'type' => [$master ? 'sometimes' : 'required', 'string', 'in:competency_name,competency_type,development_program,review_tools'],
            'value_en' => [
                'required', 'string', 'max:255',
                Rule::unique('development_plan_masters', 'value')
                    ->where('type', $type)
                    ->where('development_model_id', $request->input('development_model_id'))
                    ->ignore($master?->id),
            ],
            'value_id' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string'],
            'description_id' => ['nullable', 'string'],
            'development_model_id' => ['nullable', 'integer', 'exists:development_models,id'],
            'competency_type_id' => [
                'nullable', 'integer',
                Rule::exists('development_plan_masters', 'id')->where('type', 'competency_type'),
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
