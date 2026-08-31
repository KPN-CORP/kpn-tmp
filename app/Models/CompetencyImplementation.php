<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Master Implementation" maps one competency (at one or more proficiency
 * levels) onto a corporate org scope: grades plus the business unit -> job
 * family / function -> position hierarchy. The scope values are raw kpncorp
 * strings, intentionally not foreign keys across the connection.
 */
class CompetencyImplementation extends Model
{
    protected $fillable = [
        'competency_type_id',
        'competency_id',
        'business_unit',
        'job_family',
        'function_name',
        'position',
    ];

    public function competencyType(): BelongsTo
    {
        return $this->belongsTo(CompetencyType::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    /**
     * The grades (employee `job_level`) this implementation is scoped to. An
     * empty list means it applies to every grade.
     */
    public function grades(): HasMany
    {
        return $this->hasMany(ImplementationGrade::class, 'implementation_id');
    }

    public function proficiencyLevels(): BelongsToMany
    {
        return $this->belongsToMany(
            ProficiencyLevel::class,
            'implementation_proficiency_level',
            'implementation_id',
            'proficiency_level_id',
        );
    }
}
