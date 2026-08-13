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
        $model = $this->route('developmentModel');
        $modelId = $model->id;
        // A model stays in its package on edit; scope uniqueness / the running
        // percentage sum to that package.
        $packageId = $model->development_model_package_id;

        return [
            'name_en' => [
                'required', 'string', 'max:255',
                Rule::unique('development_models', 'name')
                    ->where('development_model_package_id', $packageId)
                    ->ignore($modelId),
            ],
            'name_id' => ['nullable', 'string', 'max:255'],
            'percentage' => ['required', 'integer', 'min:1', new SumPercentageCheck($packageId, $modelId)],
            'description_en' => ['nullable', 'string'],
            'description_id' => ['nullable', 'string'],
            'replace_with' => ['nullable', 'integer', 'exists:development_models,id'],
        ];
    }
}
