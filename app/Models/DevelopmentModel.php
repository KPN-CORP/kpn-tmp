<?php

namespace App\Models;

use Database\Factories\DevelopmentModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DevelopmentModel extends Model
{
    /** @use HasFactory<DevelopmentModelFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'development_model_package_id',
        'name',
        'name_en',
        'name_id',
        'percentage',
        'description_en',
        'description_id',
    ];

    /**
     * The period-scoped package this model belongs to.
     */
    public function developmentModelPackage(): BelongsTo
    {
        return $this->belongsTo(DevelopmentModelPackage::class);
    }

    /**
     * Master "development program" entries filed under this model.
     */
    public function developmentPrograms(): HasMany
    {
        return $this->hasMany(DevelopmentPlanMaster::class, 'development_model_id')
            ->where('type', 'development_program');
    }

    public function individualDevelopmentPlans(): HasMany
    {
        return $this->hasMany(IndividualDevelopmentPlan::class, 'development_model_id');
    }
}
