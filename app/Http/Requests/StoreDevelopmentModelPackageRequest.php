<?php

namespace App\Http\Requests;

use App\Models\DevelopmentModel;
use App\Models\DevelopmentModelPackage;
use App\Models\DevelopmentProgram;
use App\Models\IndividualDevelopmentPlan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A package and its weighted development models are saved together: the models
 * only mean anything as a set (their percentages have to total 100%), so they
 * are submitted as nested rows rather than one at a time.
 *
 * A row carries its own `id` when stored and none when just added, which is how
 * the writer tells an update from an insert.
 */
class StoreDevelopmentModelPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view_idp_master') ?? false;
    }

    /**
     * Drop the model rows that carry nothing at all - no name in either
     * language, no description in either. A row the user added and then thought
     * better of is not an error.
     *
     * The row keys are deliberately NOT re-indexed: an error comes back keyed by
     * position (models.2.name_en) and the form still has the blank row on
     * screen, so renumbering would pin the error to the wrong row.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('models')) {
            return;
        }

        $fields = ['name_en', 'name_id', 'description_en', 'description_id'];

        $rows = collect((array) $this->input('models'))
            ->map(fn ($row) => (array) $row)
            ->reject(fn (array $row) => collect($fields)->every(
                fn (string $field) => trim((string) ($row[$field] ?? '')) === ''
            ))
            ->map(function (array $row) {
                // Inertia posts JSON, so the checkbox arrives as a real bool -
                // but a form-encoded post would send "true"/"false", which the
                // `boolean` rule rejects. Normalize whatever arrives; a value
                // that means neither becomes null and still fails the rule.
                if (array_key_exists('uses_master_training', $row)) {
                    $row['uses_master_training'] = filter_var(
                        $row['uses_master_training'],
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    );
                }

                return $row;
            })
            ->all();

        $this->merge(['models' => $rows]);
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

            // The package's weighted models, as a set.
            'models' => ['required', 'array', 'min:1'],
            'models.*.id' => ['nullable', 'integer'],
            'models.*.name_en' => ['required', 'string', 'max:255'],
            'models.*.name_id' => ['nullable', 'string', 'max:255'],
            'models.*.percentage' => ['required', 'integer', 'min:1', 'max:100'],
            // Whether what this model develops is drawn from the Master
            // Training catalogue rather than written out by hand.
            'models.*.uses_master_training' => ['boolean'],
            'models.*.description_en' => ['nullable', 'string'],
            'models.*.description_id' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The end date cannot be before the start date.',
            'models.required' => 'Add at least one development model - a package with no models cannot reach 100%.',
            'models.min' => 'Add at least one development model - a package with no models cannot reach 100%.',
            'models.*.name_en.required' => 'This development model needs an English name.',
            'models.*.percentage.required' => 'This development model needs a percentage.',
            'models.*.percentage.integer' => 'The percentage must be a whole number.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->assertPeriodFree($validator);
            $this->assertPinnableToday($validator);
            $this->assertModelsConsistent($validator);
            $this->assertRemovedModelsUnused($validator);
        });
    }

    /**
     * Package windows must not overlap (a null end date extends to infinity),
     * so the active-package resolution stays unambiguous.
     */
    protected function assertPeriodFree(Validator $validator): void
    {
        $start = $this->date('start_date');
        $end = $this->date('end_date');

        $overlaps = DevelopmentModelPackage::query()
            ->when($this->ignorePackageId(), fn ($q, $id) => $q->where('id', '!=', $id))
            ->where(function ($q) use ($start, $end) {
                // Existing.start <= new.end (or new is open) ...
                $q->where(function ($q) use ($end) {
                    if ($end) {
                        $q->whereDate('start_date', '<=', $end);
                    }
                });
                // ... AND existing.end >= new.start (or existing is open).
                $q->where(function ($q) use ($start) {
                    $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start);
                });
            })
            ->exists();

        if ($overlaps) {
            $validator->errors()->add('start_date', 'This period overlaps an existing package.');
        }
    }

    /**
     * A package can only be pinned active while its period covers today: not
     * yet started, or already expired, cannot be the active package.
     */
    protected function assertPinnableToday(Validator $validator): void
    {
        if (! $this->boolean('is_current')) {
            return;
        }

        $start = $this->date('start_date');
        $end = $this->date('end_date');
        $today = today();

        if ($start->gt($today)) {
            $validator->errors()->add(
                'is_current',
                'This package cannot be set as active yet - its period starts in the future.',
            );
        } elseif ($end && $end->lt($today)) {
            $validator->errors()->add(
                'is_current',
                'This package cannot be set as active - its period has already ended.',
            );
        }
    }

    /**
     * The models are a set: their names have to be distinct within the package
     * (the DB's unique index says so) and their percentages have to total
     * exactly 100% - a package is what drives an employee's development effort
     * split, so a partial split is not saveable.
     */
    protected function assertModelsConsistent(Validator $validator): void
    {
        $rows = collect((array) $this->input('models'))->map(fn ($row) => (array) $row);

        $names = $rows->map(fn (array $row) => mb_strtolower(trim((string) ($row['name_en'] ?? ''))));
        $duplicates = $names->duplicates();

        if ($duplicates->isNotEmpty()) {
            // Flag every row carrying a repeated name, so the form can point at
            // each of them rather than only the second one.
            foreach ($names as $index => $name) {
                if ($duplicates->contains($name)) {
                    $validator->errors()->add(
                        "models.{$index}.name_en",
                        'Two development models in this package cannot share a name.',
                    );
                }
            }
        }

        $total = $rows->sum(fn (array $row) => (int) ($row['percentage'] ?? 0));

        if ($total !== 100) {
            $validator->errors()->add(
                'models',
                "The development models must total exactly 100% - they currently total {$total}%.",
            );
        }
    }

    /**
     * A model dropped from the form is deleted, so it cannot be one an IDP plan
     * or a development program still points at.
     */
    protected function assertRemovedModelsUnused(Validator $validator): void
    {
        $packageId = $this->ignorePackageId();

        if ($packageId === null) {
            return;
        }

        $kept = collect((array) $this->input('models'))
            ->map(fn ($row) => (int) (((array) $row)['id'] ?? 0))
            ->filter()
            ->all();

        $removed = DevelopmentModel::where('development_model_package_id', $packageId)
            ->when($kept !== [], fn ($q) => $q->whereNotIn('id', $kept))
            ->get();

        foreach ($removed as $model) {
            $blocker = match (true) {
                IndividualDevelopmentPlan::where('development_model_id', $model->id)->exists() => 'it is used in employee development plans',
                DevelopmentProgram::where('development_model_id', $model->id)->exists() => 'it is assigned to development programs',
                default => null,
            };

            if ($blocker !== null) {
                $validator->errors()->add(
                    'models',
                    "Cannot remove '{$model->name}': {$blocker}.",
                );
            }
        }
    }

    protected function ignorePackageId(): ?int
    {
        return null;
    }
}
