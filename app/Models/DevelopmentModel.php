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
        'uses_master_training',
        'description_en',
        'description_id',
    ];

    protected $casts = [
        'uses_master_training' => 'boolean',
    ];

    /**
     * Without this a model created without the field has no `uses_master_training`
     * on the in-memory instance at all (it only picks up the column default on
     * reload), which would read as null rather than false.
     */
    protected $attributes = [
        'uses_master_training' => false,
    ];

    /**
     * The period-scoped package this model belongs to.
     */
    public function developmentModelPackage(): BelongsTo
    {
        return $this->belongsTo(DevelopmentModelPackage::class);
    }

    /**
     * Development programs filed under this model.
     */
    public function developmentPrograms(): HasMany
    {
        return $this->hasMany(DevelopmentProgram::class);
    }

    public function individualDevelopmentPlans(): HasMany
    {
        return $this->hasMany(IndividualDevelopmentPlan::class, 'development_model_id');
    }
}
