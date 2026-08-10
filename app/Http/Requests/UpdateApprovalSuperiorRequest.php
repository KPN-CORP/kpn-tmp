<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApprovalSuperiorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view_approval_setting') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'layers' => ['nullable', 'array'],
            'layers.*' => ['nullable', 'string', 'max:25'],
        ];
    }
}
