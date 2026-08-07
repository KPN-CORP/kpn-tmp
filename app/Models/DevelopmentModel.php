<?php

namespace App\Models;

use Database\Factories\DevelopmentModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DevelopmentModel extends Model
{
    /** @use HasFactory<DevelopmentModelFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'name_en',
        'name_id',
        'percentage',
        'description_en',
        'description_id',
    ];

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
