<?php

namespace App\Http\Requests;

use App\Rules\SumPercentageCheck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDevelopmentModelRequest extends FormRequest
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
        $packageId = $this->input('development_model_package_id');

        return [
            'development_model_package_id' => ['required', 'integer', 'exists:development_model_packages,id'],
            // Names are unique within a package (the same model name may recur
            // across period packages).
            'name_en' => [
                'required', 'string', 'max:255',
                Rule::unique('development_models', 'name')
                    ->where('development_model_package_id', $packageId),
            ],
            'name_id' => ['nullable', 'string', 'max:255'],
            'percentage' => ['required', 'integer', 'min:1', new SumPercentageCheck($packageId ? (int) $packageId : null)],
            'description_en' => ['nullable', 'string'],
            'description_id' => ['nullable', 'string'],
        ];
    }
}
