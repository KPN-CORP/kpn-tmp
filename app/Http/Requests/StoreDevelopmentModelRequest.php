<?php

namespace App\Http\Requests;

use App\Rules\SumPercentageCheck;
use Illuminate\Foundation\Http\FormRequest;

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
        return [
            'name' => ['required', 'string', 'max:255', 'unique:development_models,name'],
            'percentage' => ['required', 'integer', 'min:1', new SumPercentageCheck()],
        ];
    }
}
