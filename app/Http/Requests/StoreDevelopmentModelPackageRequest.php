<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\MasterBisnisunit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class StoreDevelopmentModelPackageRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // Audience scope: which corporate business units + grade levels this
            // package applies to. Both are required and non-empty — there is no
            // catch-all package. Membership is checked against the corporate
            // master lists only when those lists are reachable, so a downed
            // kpncorp connection degrades to a plain "array of strings" check.
            'business_units' => ['required', 'array', 'min:1'],
            'business_units.*' => array_filter(['required', 'string', $this->businessUnitRule()]),
            'grades' => ['required', 'array', 'min:1'],
            'grades.*' => array_filter(['required', 'string', $this->gradeRule()]),
            'start_date' => ['required', 'date'],
            // Null end date = ongoing package.
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // Force-active override: keep this package in effect regardless of its
            // date window. Not globally exclusive — scoping keeps audiences apart.
            'is_current' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The end date cannot be before the start date.',
            'business_units.required' => 'Select at least one business unit.',
            'business_units.min' => 'Select at least one business unit.',
            'grades.required' => 'Select at least one grade level.',
            'grades.min' => 'Select at least one grade level.',
        ];
    }

    /**
     * Restrict business units to the corporate master list when it is reachable.
     */
    private function businessUnitRule(): ?In
    {
        try {
            $values = MasterBisnisunit::pluck('nama_bisnis')->filter()->all();
        } catch (\Throwable) {
            return null;
        }

        return $values ? Rule::in($values) : null;
    }

    /**
     * Restrict grades to the grade levels actually present on employees.
     */
    private function gradeRule(): ?In
    {
        try {
            $values = Employee::query()->distinct()->pluck('job_level')->filter()->all();
        } catch (\Throwable) {
            return null;
        }

        return $values ? Rule::in($values) : null;
    }
}
