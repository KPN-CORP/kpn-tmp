<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DevelopmentModel extends Model
{
    /** @use HasFactory<\Database\Factories\DevelopmentModelFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'percentage',
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
