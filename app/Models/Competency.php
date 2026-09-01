<?php

namespace App\Models;

use App\Models\Concerns\HasEffectivePeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named competency, filed under a competency type, pinned to any number of
 * proficiency levels and (under those levels) key behaviors, and linked to the
 * development programs that build it.
 */
class Competency extends Model
{
    use HasEffectivePeriod;

    protected $table = 'competencies';

    protected $fillable = [
        'competency_type_id',
        'name_en',
        'name_id',
        'description_en',
        'description_id',
        'effective_start_date',
        'effective_end_date',
    ];

    protected $casts = [
        'effective_start_date' => 'date',
        'effective_end_date' => 'date',
    ];

    public function competencyType(): BelongsTo
    {
        return $this->belongsTo(CompetencyType::class);
    }

    public function proficiencyLevels(): BelongsToMany
    {
        return $this->belongsToMany(ProficiencyLevel::class);
    }

    public function keyBehaviors(): BelongsToMany
    {
        return $this->belongsToMany(KeyBehavior::class);
    }

    /**
     * The development programs that build this competency: the real
     * many-to-many that replaced the `related_program` json id list.
     */
    public function developmentPrograms(): BelongsToMany
    {
        return $this->belongsToMany(DevelopmentProgram::class);
    }

    public function implementations(): HasMany
    {
        return $this->hasMany(CompetencyImplementation::class);
    }

    /**
     * Master trainings that build this competency.
     */
    public function trainings(): HasMany
    {
        return $this->hasMany(Training::class);
    }
}
