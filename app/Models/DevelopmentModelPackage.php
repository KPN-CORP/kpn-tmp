<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A period-scoped bundle of development models. The "active" package (the one
 * whose models drive new IDP plans) is resolved from the current date, with the
 * manual `is_current` pin guaranteeing an ongoing package stays active.
 */
class DevelopmentModelPackage extends Model
{
    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'is_current',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function developmentModels(): HasMany
    {
        return $this->hasMany(DevelopmentModel::class);
    }

    /**
     * The package in effect today: the one whose window contains the current
     * date (a null end date means ongoing). Falls back to the pinned current
     * package, then to the most recently started one.
     */
    public static function active(): ?self
    {
        $today = today();

        return static::query()
            ->whereDate('start_date', '<=', $today)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->orderByDesc('start_date')
            ->first()
            ?? static::where('is_current', true)->orderByDesc('start_date')->first()
            ?? static::orderByDesc('start_date')->first();
    }
}
