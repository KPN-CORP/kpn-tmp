<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve / reject an IDP approval step. A note is always required — every
 * decision must be justified.
 */
class ActOnIdpApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Per-layer authorization (is this user the current approver?) is
        // enforced in the service; any signed-in user may attempt to act.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:1000'],
        ];
    }

    public function note(): string
    {
        return trim((string) $this->validated()['note']);
    }
}
