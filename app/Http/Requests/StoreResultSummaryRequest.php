<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreResultSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('input_successor_position') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string'],
            'critical_position' => ['nullable', 'string', 'max:100'],
            'successor_type' => ['nullable', 'string', 'max:100'],
            'successor_to_position' => ['nullable', 'string', 'max:100'],
        ];
    }
}
