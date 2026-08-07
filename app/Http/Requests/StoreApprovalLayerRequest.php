<?php

namespace App\Http\Requests;

use App\Models\ApprovalLayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalLayerRequest extends FormRequest
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
            'approval_flow_id' => ['required', 'integer', 'exists:approval_flows,id'],
            'name' => ['required', 'string', 'max:255'],
            'approver_type' => ['required', Rule::in(ApprovalLayer::TYPES)],
            // Required only when the approver is a specifically chosen employee.
            'approver_employee_id' => [
                Rule::requiredIf(fn () => $this->input('approver_type') === ApprovalLayer::TYPE_SPECIFIC),
                'nullable',
                'string',
                'max:25',
            ],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // A dynamic (manager) layer never carries a specific approver.
        if ($this->input('approver_type') !== ApprovalLayer::TYPE_SPECIFIC) {
            $this->merge(['approver_employee_id' => null]);
        }
    }
}
