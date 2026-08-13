<?php

namespace App\Rules;

use App\Models\DevelopmentModel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The development models' percentages (70-20-10 style) must never exceed 100%
 * in total within a single package. Pass the package id to scope the running
 * sum, and (when editing) the model's id to exclude it from that sum.
 */
class SumPercentageCheck implements ValidationRule
{
    public function __construct(
        private readonly ?int $packageId = null,
        private readonly ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $currentSum = DevelopmentModel::query()
            ->when($this->packageId, fn ($q) => $q->where('development_model_package_id', $this->packageId))
            ->when($this->ignoreId, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->sum('percentage');

        if (($currentSum + (int) $value) > 100) {
            $fail('The total percentage of all development models in this package cannot exceed 100%.');
        }
    }
}
