<?php

namespace App\Rules;

use App\Models\DevelopmentModel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The development models' percentages (70-20-10 style) must never exceed 100%
 * in total. When editing an existing model, pass its id to exclude it from the
 * running sum.
 */
class SumPercentageCheck implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $currentSum = DevelopmentModel::query()
            ->when($this->ignoreId, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->sum('percentage');

        if (($currentSum + (int) $value) > 100) {
            $fail('The total percentage of all development models cannot exceed 100%.');
        }
    }
}
