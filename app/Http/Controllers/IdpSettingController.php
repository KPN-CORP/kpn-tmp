<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDevelopmentModelRequest;
use App\Http\Requests\UpdateDevelopmentModelRequest;
use App\Models\DevelopmentModel;
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
            ->get(['id', 'value', 'value_en', 'value_id', 'description_en', 'description_id', 'related_program'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'value' => $c->value,
                'value_en' => $c->value_en,
                'value_id' => $c->value_id,
                'description_en' => $c->description_en,
                'description_id' => $c->description_id,
                'related_program' => collect($c->related_program ?? [])->map(fn ($id) => (int) $id)->values(),
                'linked_programs' => collect($c->related_program ?? [])
                    ->map(fn ($id) => $programValues->get($id)?->value)
                    ->filter()
                    ->values(),
            ]);

        return Inertia::render('Idp/Settings', [
            'developmentModels' => DevelopmentModel::orderByDesc('percentage')->orderBy('name')
                ->withCount(['developmentPrograms', 'individualDevelopmentPlans'])
                ->get(),
            'totalPercentage' => (int) DevelopmentModel::sum('percentage'),
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
                ->orderBy('value')->get(['id', 'value']),
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

    // --- Master data (competency_name / development_program / review_tools) ---

    public function storeMaster(Request $request): RedirectResponse
    {
        $data = $this->validateMaster($request);

        $isCompetency = $data['type'] === 'competency_name';

        $master = DevelopmentPlanMaster::create([
            'type' => $data['type'],
            // Canonical `value` (grouping/ordering) tracks the English name.
            'value' => $data['value_en'],
            'value_en' => $data['value_en'],
            'value_id' => $data['value_id'] ?? null,
            'description_en' => $isCompetency ? ($data['description_en'] ?? null) : null,
            'description_id' => $isCompetency ? ($data['description_id'] ?? null) : null,
            'development_model_id' => $data['development_model_id'] ?? null,
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

        if ($master->type === 'competency_name') {
            $master->description_en = $data['description_en'] ?? null;
            $master->description_id = $data['description_id'] ?? null;

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
            'type' => [$master ? 'sometimes' : 'required', 'string', 'in:competency_name,development_program,review_tools'],
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
