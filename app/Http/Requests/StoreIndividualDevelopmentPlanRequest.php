<?php

namespace App\Http\Requests;

use App\Models\Competency;
use App\Models\CompetencyType;
use App\Models\DevelopmentProgram;
use App\Models\IndividualDevelopmentPlan;
use App\Models\ReviewTool;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreIndividualDevelopmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'employee_id' => ['required', 'string'],
        ], $this->planRules());
    }

    /**
     * Rules shared between create and update.
     *
     * @return array<string, mixed>
     */
    protected function planRules(): array
    {
        return [
            'development_model_id' => ['required', 'integer', 'exists:development_models,id'],
            // Checked against the competency_types master in withValidator(),
            // where the plan's own stored value can be exempted.
            'competency_type' => ['required', 'string', 'max:255'],
            'competency_name' => ['required', 'string'],
            'development_program' => ['required', 'string'],
            'review_tools' => ['nullable', 'string'],
            'expected_outcome' => ['nullable', 'string', 'max:500'],
            'time_frame_start' => ['required', 'date'],
            'time_frame_end' => ['nullable', 'date', 'after_or_equal:time_frame_start'],
            'realization_date' => ['nullable', 'date', 'after_or_equal:time_frame_start'],
            'result_evidence' => ['required_with:realization_date', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'time_frame_end.after_or_equal' => 'The end date cannot be before the start date.',
            'realization_date.after_or_equal' => 'The realization date cannot be before the start date.',
            'result_evidence.required_with' => 'Result evidence is required when a realization date is set.',
        ];
    }

    /**
     * The plan being edited, or null when creating. Drives the "already stored"
     * exemption: what a row holds is never rejected, so deactivating a master
     * can never make an unrelated edit impossible.
     */
    protected function currentPlan(): ?IndividualDevelopmentPlan
    {
        return null;
    }

    /**
     * Cross-master checks the dropdowns already enforce, mirrored server-side:
     *
     *  - the competency type must be one of the `competency_types` masters;
     *  - the chosen competency name and review tool must be ACTIVE masters;
     *  - the competency must belong to the chosen competency type;
     *  - the competency must reach at least one development program filed
     *    under the chosen development model;
     *  - the development program must be one linked to the chosen competency,
     *    and must be filed under both the chosen development model and the
     *    chosen competency type.
     *
     * The catch-all "Others" type gets exactly the same treatment as any other:
     * it picks its competency from the master data too.
     *
     * Each check exempts the value the plan already stores, and each is skipped
     * when the submitted name matches no master at all (legacy plans hold free
     * text, and those must stay editable).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = trim((string) $this->input('competency_type'));
            $name = trim((string) $this->input('competency_name'));
            $program = trim((string) $this->input('development_program'));
            $reviewTool = trim((string) $this->input('review_tools'));
            $plan = $this->currentPlan();

            // --- the competency type must be one of the masters ---
            $types = CompetencyType::get(['id', 'name_en']);
            $chosenType = $types->first(fn (CompetencyType $ct) => strcasecmp(trim((string) $ct->name_en), $type) === 0);

            if ($type !== '' && ! $chosenType && $type !== trim((string) $plan?->competency_type)) {
                $validator->errors()->add(
                    'competency_type',
                    'The selected competency type is not one of the configured competency types.',
                );

                return;
            }

            // --- review tool must be active ---
            if ($reviewTool !== '' && $reviewTool !== trim((string) $plan?->review_tools)) {
                $tools = ReviewTool::where('name_en', $reviewTool)->get();

                if ($tools->isNotEmpty() && $tools->every(fn (ReviewTool $t) => ! $t->isActive())) {
                    $validator->errors()->add(
                        'review_tools',
                        'The selected review tool is inactive. Please choose an active one.',
                    );
                }
            }

            $competencies = $name === ''
                ? collect()
                : Competency::with([
                    'competencyType:id,name_en',
                    'developmentPrograms:id,name_en,development_model_id,competency_type_id',
                    'developmentPrograms.competencyType:id,name_en',
                ])
                    ->where('name_en', $name)
                    ->get();

            // --- competency name must be active ---
            if ($competencies->isNotEmpty()
                && $competencies->every(fn (Competency $c) => ! $c->isActive())
                && $name !== trim((string) $plan?->competency_name)
            ) {
                $validator->errors()->add(
                    'competency_name',
                    'The selected competency name is inactive. Please choose an active one.',
                );
            }

            if ($competencies->isEmpty() || $type === '') {
                return;
            }

            // A competency is always filed under a type (the master form requires
            // one), so the type scopes it strictly — an untyped competency is
            // legacy data and belongs under no type at all.
            $matchesType = fn (?string $masterType) => $masterType !== null
                && trim($masterType) !== ''
                && strcasecmp(trim($masterType), $type) === 0;

            // Development programs go the other way: an untyped program is
            // global. They are narrowed primarily by the competency they build,
            // and treating a missing type as "none" would reject everything.
            $fitsType = fn (?string $masterType) => $masterType === null
                || trim($masterType) === ''
                || strcasecmp(trim($masterType), $type) === 0;

            $modelId = $this->input('development_model_id');
            $modelId = is_numeric($modelId) ? (int) $modelId : null;

            // A program is filed under one development model (the 70-20-10
            // split) and the plan is being added under one, so only that
            // model's programs may fill it. A program with no model is legacy
            // data and, like an untyped master, counts as global.
            $fitsModel = fn (?int $programModel) => $programModel === null
                || $modelId === null
                || $programModel === $modelId;

            // A program the plan could actually pick: filed under this plan's
            // development model, and under this plan's competency type.
            $selectable = fn (DevelopmentProgram $p) => $fitsModel($p->development_model_id)
                && $fitsType($p->competencyType?->name_en);

            // --- competency must belong to the chosen type ---
            $typed = $competencies->filter(fn (Competency $c) => $matchesType($c->competencyType?->name_en));

            if ($typed->isEmpty() && $name !== trim((string) $plan?->competency_name)) {
                $validator->errors()->add(
                    'competency_name',
                    'The selected competency name does not belong to the chosen competency type.',
                );

                return;
            }

            // --- the competency must reach a program under the chosen model ---
            // A competency reaches its programs through the master link, and a
            // plan is filed under exactly one development model, so a
            // competency whose linked programs all sit under other models
            // cannot be developed here — which is why the picker leaves it off
            // the list. A competency with no links at all is global (the
            // program picker then offers the whole catalogue), so it is exempt.
            if ($name !== trim((string) $plan?->competency_name)) {
                $links = $typed->flatMap(fn (Competency $c) => $c->developmentPrograms);

                if ($links->isNotEmpty() && $links->every(fn (DevelopmentProgram $p) => ! $selectable($p))) {
                    $validator->errors()->add(
                        'competency_name',
                        'The selected competency name has no development program filed under the chosen development model.',
                    );

                    return;
                }
            }

            // --- program must fit the model, the competency, and the type ---
            if ($program === '' || $program === trim((string) $plan?->development_program)) {
                return;
            }

            $linked = ($typed->isNotEmpty() ? $typed : $competencies)
                ->flatMap(fn (Competency $c) => $c->developmentPrograms);

            // A competency with no links at all falls back to the whole
            // catalogue (still narrowed to the model and the type), which is
            // exactly what the picker offers.
            $candidates = $linked->isNotEmpty()
                ? $linked
                : DevelopmentProgram::with('competencyType:id,name_en')
                    ->where('name_en', $program)
                    ->get(['id', 'name_en', 'development_model_id', 'competency_type_id']);

            // A name matching no program at all is free text on a legacy plan,
            // so there is nothing to police here.
            if ($candidates->isEmpty()) {
                return;
            }

            $allowed = $candidates
                ->filter($selectable)
                ->pluck('name_en')
                ->map(fn ($n) => trim((string) $n))
                ->all();

            if (! in_array($program, $allowed, true)) {
                $validator->errors()->add(
                    'development_program',
                    'The selected development program is not valid for the chosen development model, competency name and type.',
                );
            }
        });
    }
}
