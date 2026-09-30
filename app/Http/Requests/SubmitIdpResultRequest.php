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
            // What was actually reached, in the plan's own unit. Required for
            // the same reason the target is: a result with no number cannot be
            // judged against the target it was filed for. The column stays
            // nullable because the results filed before this have none.
            'achievement' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            // Evidence has to be a LINK: something an approver can open and
            // check. A sentence describing the evidence is not evidence.
            'result_evidence' => ['required', 'string', 'max:1000', 'url:http,https'],
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
            'achievement.required' => 'Enter what was actually achieved.',
            'achievement.numeric' => 'The achievement must be a number.',
            'result_evidence.required' => 'Paste the link to the evidence for this result.',
            'result_evidence.url' => 'The evidence must be a link an approver can open, e.g. https://drive.google.com/…',
        ];
    }

    /**
     * Someone pasting a link routinely leaves the scheme off — `drive.google.com/…`
     * or `www.example.com`. That is a link by any ordinary reading, so it is
     * completed to `https://` rather than rejected; anything that does not look
     * like a host is left alone and fails the rule with its own message.
     */
    protected function prepareForValidation(): void
    {
        // An empty number input posts `''`, which would fail `numeric` with a
        // "not a number" message for a field that is simply missing. Blanking
        // it lets `required` report it, and catches a whitespace-only entry.
        $this->merge([
            'achievement' => trim((string) $this->input('achievement')) === ''
                ? null
                : $this->input('achievement'),
        ]);

        $evidence = trim((string) $this->input('result_evidence'));

        if ($evidence !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $evidence)) {
            // A host is at least `something.tld`, before any slash, space or
            // query — which is what separates a bare domain from a sentence.
            $head = preg_split('#[/?\s]#', $evidence, 2)[0];

            if (preg_match('#^[\w-]+(\.[\w-]+)+$#', $head)) {
                $evidence = 'https://'.$evidence;
            }
        }

        $this->merge(['result_evidence' => $evidence]);
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
