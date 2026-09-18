<?php

namespace App\Http\Requests;

use App\Models\IndividualDevelopmentPlan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * File one program's RESULT: when it was realized and the evidence for it.
 *
 * These two fields are deliberately not part of the plan form — the planning
 * stage approves what will be done, this stage reports what was. Saving them
 * always goes through here, whether the result is only being drafted or
 * submitted for approval straight away.
 */
class SubmitIdpResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Visibility is checked in the controller (EmployeeScopeService).
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $start = $this->plan()?->time_frame_start?->toDateString();

        return [
            'realization_date' => array_filter([
                'required',
                'date',
                $start ? 'after_or_equal:'.$start : null,
            ]),
            'result_evidence' => ['required', 'string', 'max:1000'],
            // Whether to send it up the approval chain now, or only save the
            // result so it can be finished later. Not named `submit`: that is an
            // Inertia form method, and a data field by that name never arrives.
            'submit_for_approval' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'realization_date.required' => 'Enter the date this program was realized.',
            'realization_date.after_or_equal' => 'The realization date cannot be before the program started.',
            'result_evidence.required' => 'Describe or link the evidence for this result.',
        ];
    }

    /**
     * Whether the caller asked for the result to go up the chain now. Defaults
     * to true: filing a result is normally the act of submitting it.
     */
    public function shouldSubmit(): bool
    {
        return $this->boolean('submit_for_approval', true);
    }

    private function plan(): ?IndividualDevelopmentPlan
    {
        $plan = $this->route('idp');

        return $plan instanceof IndividualDevelopmentPlan ? $plan : null;
    }
}
