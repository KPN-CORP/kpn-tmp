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
            ->with('developmentModel:id,name,percentage')
            ->orderBy('value')
            ->get(['id', 'value', 'development_model_id']);

        $programValues = $programs->keyBy('id');

        $competencies = DevelopmentPlanMaster::where('type', 'competency_name')
            ->orderBy('value')
            ->get(['id', 'value', 'related_program'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'value' => $c->value,
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
        DevelopmentModel::create($request->validated());

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

        $developmentModel->update(['name' => $data['name'], 'percentage' => $data['percentage']]);

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

        $master = DevelopmentPlanMaster::create([
            'type' => $data['type'],
            'value' => $data['value'],
            'development_model_id' => $data['development_model_id'] ?? null,
            'related_program' => $data['type'] === 'competency_name'
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
        $master->value = $data['value'];
        $master->development_model_id = $data['development_model_id'] ?? null;

        if ($master->type === 'competency_name') {
            $master->related_program = array_map('strval', $request->input('related_programs', []));
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
            'value' => [
                'required', 'string', 'max:255',
                Rule::unique('development_plan_masters', 'value')
                    ->where('type', $type)
                    ->where('development_model_id', $request->input('development_model_id'))
                    ->ignore($master?->id),
            ],
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
