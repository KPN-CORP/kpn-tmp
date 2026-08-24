<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A period-scoped, audience-scoped bundle of development models. A package
 * applies to a set of business units (kpncorp `master_bisnisunits.nama_bisnis`,
 * matched against an employee's `group_company`) and grade levels (an
 * employee's `job_level`).
 *
 * Because packages are scoped, several can be "in effect" in the same period —
 * one per business-unit / grade audience. An individual employee's *active*
 * package is the most specific package that is in effect today AND lists both
 * their business unit and their grade (see {@see activeForEmployee()}).
 */
class DevelopmentModelPackage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'business_units',
        'grades',
        'start_date',
        'end_date',
        'is_current',
    ];

    protected $casts = [
        'business_units' => 'array',
        'grades' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function developmentModels(): HasMany
    {
        return $this->hasMany(DevelopmentModel::class);
    }

    /**
     * Packages that are "in effect" today: their period covers today (a null end
     * date means ongoing), or they are manually pinned via `is_current` (a force
     * -active override that ignores the date window). Several packages may be in
     * effect at once — scoping keeps their audiences distinct.
     */
    public function scopeInEffect(Builder $query): Builder
    {
        $today = today();

        return $query->where(function (Builder $q) use ($today) {
            $q->where('is_current', true)
                ->orWhere(function (Builder $q) use ($today) {
                    $q->whereDate('start_date', '<=', $today)
                        ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today));
                });
        });
    }

    /**
     * Whether this package is in effect today (mirrors {@see scopeInEffect} for
     * a loaded model, so the settings screen can flag it without a re-query).
     */
    public function isInEffect(): bool
    {
        if ($this->is_current) {
            return true;
        }

        $today = today();

        return $this->start_date !== null
            && $this->start_date->lte($today)
            && ($this->end_date === null || $this->end_date->gte($today));
    }

    /**
     * The active package for an employee: the most specific package that is in
     * effect today and lists BOTH the employee's business unit and grade.
     *
     * "Most specific" = the narrowest audience (fewest business units + grades)
     * wins, so a business-unit-and-grade-targeted package beats a broad one;
     * ties break to the most recently started, then most recently created.
     * Returns null when nothing matches — the employee then has no addable
     * models (there is no catch-all package).
     */
    public static function activeForEmployee(?Employee $employee): ?self
    {
        $businessUnit = $employee?->group_company;
        $grade = $employee?->job_level;

        if (blank($businessUnit) || blank($grade)) {
            return null;
        }

        return static::query()
            ->inEffect()
            ->whereJsonContains('business_units', $businessUnit)
            ->whereJsonContains('grades', $grade)
            ->get()
            ->sort(function (self $a, self $b) {
                return [$a->scopeBreadth(), $b->start_date?->timestamp ?? 0, $b->id]
                    <=> [$b->scopeBreadth(), $a->start_date?->timestamp ?? 0, $a->id];
            })
            ->first();
    }

    /**
     * Resolve the active package for an employee id, loading the (kpncorp)
     * employee defensively so a missing/unreachable corporate connection simply
     * yields no active package rather than breaking the caller.
     */
    public static function activeForEmployeeId(string $employeeId): ?self
    {
        try {
            $employee = Employee::where('employee_id', $employeeId)->first();
        } catch (\Throwable) {
            return null;
        }

        return static::activeForEmployee($employee);
    }

    /**
     * Audience breadth used to rank specificity — the smaller, the more specific.
     */
    public function scopeBreadth(): int
    {
        return count($this->business_units ?? []) + count($this->grades ?? []);
    }
}
