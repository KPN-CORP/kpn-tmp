<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A period-scoped bundle of development models. Exactly one package is the
 * "active" one — the package whose models drive new IDP plans. The date window
 * decides: the active package is the one whose period contains today (windows
 * never overlap, so at most one qualifies). The manual `is_current` pin is only
 * a fallback used when no package's period covers today; it can itself only be
 * set on a package that is valid today.
 */
class DevelopmentModelPackage extends Model
{
    use SoftDeletes;

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
     * The active package — the one whose models drive new IDP plans. The date
     * window decides: the package whose period contains today (a null end date
     * means ongoing). Falls back to the manual `is_current` pin when no period
     * covers today, then to the most recently started package.
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
