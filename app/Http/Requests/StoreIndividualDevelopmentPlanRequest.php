<?php

namespace App\Http\Requests;

use App\Models\DevelopmentModel;
use App\Models\DevelopmentModelPackage;
use App\Models\DevelopmentPlanMaster;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreIndividualDevelopmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The employee this plan is for. On create it comes from the payload.
     */
    protected function targetEmployeeId(): ?string
    {
        return $this->input('employee_id');
    }

    /**
     * The plan's existing development model, if any. Null on create — used to
     * let an existing plan keep a now-historical model on edit without tripping
     * the active-package check.
     */
    protected function currentModelId(): ?int
    {
        return null;
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
            'competency_type' => ['required', 'string', 'in:Soft Competency,Technical Competency'],
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
     * For a Soft Competency, the chosen development program must be one linked
     * to the chosen competency in the master data.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateModelInActivePackage($validator);
        });

        $validator->after(function (Validator $validator) {
            if ($this->input('competency_type') !== 'Soft Competency') {
                return;
            }

            $competency = $this->input('competency_name');
            $program = $this->input('development_program');

            if (! $competency || ! $program) {
                return;
            }

            $allowed = DevelopmentPlanMaster::where('type', 'competency_name')
                ->where('value', $competency)
                ->whereNotNull('related_program')
                ->get()
                ->flatMap(function ($comp) {
                    return DevelopmentPlanMaster::where('type', 'development_program')
                        ->whereIn('id', $comp->related_program ?? [])
                        ->pluck('value');
                })
                ->all();

            if ($allowed && ! in_array($program, $allowed, true)) {
                $validator->errors()->add(
                    'development_program',
                    'The selected development program is not valid for the chosen competency name.',
                );
            }
        });
    }

    /**
     * The chosen development model must belong to the package that is active for
     * this employee — i.e. the package in effect today whose audience covers the
     * employee's business unit and grade. Editing a plan without changing its
     * model is exempt, so a plan filed under a now-historical package can still
     * be revised.
     */
    protected function validateModelInActivePackage(Validator $validator): void
    {
        $employeeId = $this->targetEmployeeId();
        $modelId = (int) $this->input('development_model_id');

        if (! $employeeId || ! $modelId || $modelId === $this->currentModelId()) {
            return;
        }

        $active = DevelopmentModelPackage::activeForEmployeeId($employeeId);

        $belongs = $active !== null
            && DevelopmentModel::where('id', $modelId)
                ->where('development_model_package_id', $active->id)
                ->exists();

        if (! $belongs) {
            $validator->errors()->add(
                'development_model_id',
                "The selected development model is not available for this employee's business unit and grade level.",
            );
        }
    }
}
