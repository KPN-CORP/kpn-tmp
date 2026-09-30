<?php

namespace App\Http\Requests;

use App\Enums\UnitOfMeasurement;
use App\Models\IndividualDevelopmentPlan;
use App\Services\Idp\Rules\PlanMasterRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // The quantitative target. REQUIRED, both halves: a plan that
            // cannot be measured cannot have its result judged against
            // anything. The columns stay nullable in the database because
            // every plan written before this rule has neither - such a row
            // still displays, but acquires a target the next time it is saved.
            'target' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'uom' => ['required', 'string', Rule::enum(UnitOfMeasurement::class)],
            'time_frame_start' => ['required', 'date'],
            'time_frame_end' => ['nullable', 'date', 'after_or_equal:time_frame_start'],
            // The realization date and the result evidence belong to the RESULT
            // stage and are filed through SubmitIdpResultRequest, not here: this
            // request only carries what the PLANNING stage approves.
        ];
    }

    /**
     * The drawer posts every field on every save, so an empty target arrives
     * as `''` rather than absent. Blanking it to null is what makes `required`
     * report it (rather than `numeric` rejecting `''` as not-a-number) and
     * keeps a stray space out of the column.
     */
    protected function prepareForValidation(): void
    {
        $blankToNull = fn (string $key) => trim((string) $this->input($key)) === ''
            ? null
            : $this->input($key);

        $this->merge([
            'target' => $blankToNull('target'),
            'uom' => $blankToNull('uom'),
        ]);
    }

    public function messages(): array
    {
        return [
            'time_frame_end.after_or_equal' => 'The end date cannot be before the start date.',
            'target.required' => 'Enter the target for this program.',
            'target.numeric' => 'The target must be a number.',
            'uom.required' => 'Choose the unit this target is measured in.',
            'uom.enum' => 'Choose one of the listed units of measurement.',
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
     *  - the competency type must hold a competency that reaches a development
     *    program filed under the chosen development model;
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
            // The cascade lives in a shared rules object so the plan form and
            // the Excel import are policed by the same code.
            $errors = app(PlanMasterRules::class)->check($this->all(), $this->currentPlan());

            foreach ($errors as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }
}
