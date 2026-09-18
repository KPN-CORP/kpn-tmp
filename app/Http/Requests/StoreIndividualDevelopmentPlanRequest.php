<?php

namespace App\Http\Requests;

use App\Models\IndividualDevelopmentPlan;
use App\Services\Idp\Rules\PlanMasterRules;
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
            // The realization date and the result evidence belong to the RESULT
            // stage and are filed through SubmitIdpResultRequest, not here: this
            // request only carries what the PLANNING stage approves.
        ];
    }

    public function messages(): array
    {
        return [
            'time_frame_end.after_or_equal' => 'The end date cannot be before the start date.',
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
