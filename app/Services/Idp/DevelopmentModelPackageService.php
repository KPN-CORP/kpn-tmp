<?php

namespace App\Services\Idp;

use App\Models\DevelopmentModel;
use App\Models\DevelopmentModelPackage;
use App\Models\DevelopmentProgram;
use App\Models\IndividualDevelopmentPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writing a development-model package and its weighted models.
 *
 * The two are one unit of work: the models only mean anything as a set (they
 * have to total 100%), so they are created, re-weighted and removed together
 * with the package that holds them, inside one transaction.
 */
class DevelopmentModelPackageService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): DevelopmentModelPackage
    {
        return DB::transaction(function () use ($data) {
            $package = DevelopmentModelPackage::create($this->attributes($data));

            $this->syncModels($package, $data['models'] ?? []);
            $this->pinCurrent($package);

            return $package;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(DevelopmentModelPackage $package, array $data): DevelopmentModelPackage
    {
        return DB::transaction(function () use ($package, $data) {
            $package->update($this->attributes($data));

            $this->syncModels($package, $data['models'] ?? []);
            $this->pinCurrent($package);

            return $package;
        });
    }

    /**
     * Why this package cannot be deleted, or null when it can be.
     *
     * The active/current package drives new development plans, so it can't be
     * removed while it's in effect (either resolved active by date, or manually
     * pinned as current) — and neither can one whose models are still
     * referenced by IDP data.
     */
    public function deletionBlocker(DevelopmentModelPackage $package): ?string
    {
        if ($package->is_current || $package->id === DevelopmentModelPackage::active()?->id) {
            return 'Cannot delete: this is the active package.';
        }

        $modelIds = $package->developmentModels()->pluck('id');

        if ($modelIds->isEmpty()) {
            return null;
        }

        if (IndividualDevelopmentPlan::whereIn('development_model_id', $modelIds)->exists()) {
            return 'Cannot delete: this package has models used in development plans.';
        }

        if (DevelopmentProgram::whereIn('development_model_id', $modelIds)->exists()) {
            return 'Cannot delete: this package has models assigned to development programs.';
        }

        return null;
    }

    /**
     * Soft delete the package and cascade to its (unused) models so they don't
     * linger without a package. Both are recoverable.
     */
    public function delete(DevelopmentModelPackage $package): void
    {
        DB::transaction(function () use ($package) {
            $modelIds = $package->developmentModels()->pluck('id');

            if ($modelIds->isNotEmpty()) {
                DevelopmentModel::whereIn('id', $modelIds)->delete();
            }

            $package->delete();
        });
    }

    /**
     * Packages with per-package roll-ups (model count + total weighting) plus
     * the active package id. Shared by the settings + development-model
     * screens.
     *
     * The roll-ups are one grouped query rather than a count per package, so
     * the list costs the same whether it shows three packages or thirty.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: int|null}
     */
    public function listData(): array
    {
        $rollup = DevelopmentModel::selectRaw(
            'development_model_package_id, COUNT(*) as models_count, COALESCE(SUM(percentage), 0) as total_percentage'
        )->groupBy('development_model_package_id')->get()->keyBy('development_model_package_id');

        $activeId = DevelopmentModelPackage::active()?->id;

        $packages = DevelopmentModelPackage::orderByDesc('start_date')->orderByDesc('id')->get()
            ->map(fn (DevelopmentModelPackage $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'start_date' => $p->start_date?->toDateString(),
                'end_date' => $p->end_date?->toDateString(),
                'is_current' => $p->is_current,
                'is_active' => $p->id === $activeId,
                'models_count' => (int) ($rollup[$p->id]->models_count ?? 0),
                'total_percentage' => (int) ($rollup[$p->id]->total_percentage ?? 0),
            ]);

        return [$packages, $activeId];
    }

    /**
     * Weighted development models with their program / plan usage counts.
     *
     * @return Collection<int, DevelopmentModel>
     */
    public function models(): Collection
    {
        return DevelopmentModel::orderByDesc('percentage')->orderBy('name')
            ->withCount(['developmentPrograms', 'individualDevelopmentPlans'])
            ->get();
    }

    /**
     * One package with its weighted models nested, as the form reads it. The
     * models carry their usage counts so the form can say why a row cannot be
     * removed before the save is attempted.
     *
     * @return array<string, mixed>
     */
    public function payload(DevelopmentModelPackage $package): array
    {
        $models = DevelopmentModel::where('development_model_package_id', $package->id)
            ->withCount(['developmentPrograms', 'individualDevelopmentPlans'])
            ->orderByDesc('percentage')
            ->orderBy('name')
            ->get();

        return [
            'id' => $package->id,
            'name' => $package->name,
            'start_date' => $package->start_date?->toDateString(),
            'end_date' => $package->end_date?->toDateString(),
            'is_current' => (bool) $package->is_current,
            'models' => $models->map(fn (DevelopmentModel $model) => [
                'id' => $model->id,
                'name_en' => $model->name_en ?? $model->name,
                'name_id' => $model->name_id,
                'percentage' => (int) $model->percentage,
                'uses_master_training' => (bool) $model->uses_master_training,
                'description_en' => $model->description_en,
                'description_id' => $model->description_id,
                'development_programs_count' => (int) $model->development_programs_count,
                'individual_development_plans_count' => (int) $model->individual_development_plans_count,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'is_current' => $data['is_current'] ?? false,
        ];
    }

    /**
     * Only one package may be pinned as current.
     */
    private function pinCurrent(DevelopmentModelPackage $package): void
    {
        if ($package->is_current) {
            DevelopmentModelPackage::where('id', '!=', $package->id)->update(['is_current' => false]);
        }
    }

    /**
     * Bring a package's weighted models in step with the submitted rows.
     *
     * Same id-preserving contract the competency form's nested rows follow: a
     * row that comes back with its own id is updated in place (so renaming or
     * re-weighting a model keeps its identity, and the plans and programs
     * pointing at it keep pointing at it), rows with no id - or an id belonging
     * to another package - are created, and rows the form no longer carries are
     * deleted. A row still referenced is never reached: the request rejects the
     * save before this runs.
     */
    private function syncModels(DevelopmentModelPackage $package, mixed $rows): void
    {
        $existing = DevelopmentModel::where('development_model_package_id', $package->id)
            ->get()
            ->keyBy('id');

        $keep = [];

        foreach ((array) $rows as $row) {
            $row = (array) $row;

            $nameEn = trim((string) ($row['name_en'] ?? ''));

            if ($nameEn === '') {
                continue;
            }

            $attributes = [
                // The canonical `name` (what grouping / ordering and the unique
                // index key on) stays in step with the English name.
                'name' => $nameEn,
                'name_en' => $nameEn,
                'name_id' => $this->blankToNull($row['name_id'] ?? null),
                'percentage' => (int) ($row['percentage'] ?? 0),
                'uses_master_training' => filter_var(
                    $row['uses_master_training'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                ),
                'description_en' => $this->blankToNull($row['description_en'] ?? null),
                'description_id' => $this->blankToNull($row['description_id'] ?? null),
            ];

            $current = $existing->get((int) ($row['id'] ?? 0));

            if ($current !== null) {
                $current->update($attributes);
                $keep[] = $current->id;

                continue;
            }

            $keep[] = DevelopmentModel::create(
                $attributes + ['development_model_package_id' => $package->id]
            )->id;
        }

        DevelopmentModel::where('development_model_package_id', $package->id)
            ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
