<?php

namespace App\Http\Requests;

use App\Rules\SumPercentageCheck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDevelopmentModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view_idp_master') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $modelId = $this->route('developmentModel')->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('development_models', 'name')->ignore($modelId)],
            'percentage' => ['required', 'integer', 'min:1', new SumPercentageCheck($modelId)],
            'description_en' => ['nullable', 'string'],
            'description_id' => ['nullable', 'string'],
            'replace_with' => ['nullable', 'integer', 'exists:development_models,id'],
        ];
    }
}
