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
            // Evidence has to be something an approver can OPEN and check: a web
            // link, or a path on the office network (a shared folder). A
            // sentence describing the evidence is not evidence.
            'result_evidence' => [
                'required',
                'string',
                'max:1000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! self::isEvidenceLocation((string) $value)) {
                        $fail('The evidence must be a link or a shared-folder path an approver can open, e.g. https://drive.google.com/… or \\\\fileserver\\HR\\certificate.pdf');
                    }
                },
            ],
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
        ];
    }

    /**
     * Someone pasting a link routinely leaves the scheme off — `drive.google.com/…`
     * or `www.example.com`. That is a link by any ordinary reading, so it is
     * completed to `https://` rather than rejected; anything that does not look
     * like a host is left alone and fails the rule with its own message.
     */
    /**
     * A web link (http / https only — this value is rendered as a link, so
     * javascript: and friends must never pass), or a path on the office
     * network: a UNC path (\\\\server\\share\\…), a mapped drive (S:\\…) or a
     * file:// link. Folder and file names may contain spaces, so only the
     * start of a path is checked. The UI never renders a path as a link — it
     * shows it with a Copy button — so a path is inert text however it reads.
     */
    public static function isEvidenceLocation(string $value): bool
    {
        $value = trim($value);

        if (preg_match('#^(\\\\\\\\|//)[^\\\\/\s]+[\\\\/][^\\\\/]+#', $value)
            || preg_match('#^[a-z]:[\\\\/]\S#i', $value)
            || preg_match('#^file://\S#i', $value)) {
            return true;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

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
