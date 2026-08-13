<?php

namespace App\Http\Requests;

use App\Models\DevelopmentModelPackage;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

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
            'start_date' => ['required', 'date'],
            // Null end date = ongoing package.
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_current' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The end date cannot be before the start date.',
        ];
    }

    /**
     * Package windows must not overlap (a null end date extends to infinity),
     * so the active-package resolution stays unambiguous.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = $this->date('start_date');
            $end = $this->date('end_date');

            $overlaps = DevelopmentModelPackage::query()
                ->when($this->ignorePackageId(), fn ($q, $id) => $q->where('id', '!=', $id))
                ->where(function ($q) use ($start, $end) {
                    // Existing.start <= new.end (or new is open) …
                    $q->where(function ($q) use ($end) {
                        if ($end) {
                            $q->whereDate('start_date', '<=', $end);
                        }
                    });
                    // … AND existing.end >= new.start (or existing is open).
                    $q->where(function ($q) use ($start) {
                        $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start);
                    });
                })
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('start_date', 'This period overlaps an existing package.');
            }
        });
    }

    protected function ignorePackageId(): ?int
    {
        return null;
    }
}
