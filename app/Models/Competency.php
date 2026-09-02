<?php

namespace App\Models;

use App\Models\Concerns\HasActiveState;
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
    use HasActiveState;

    protected $table = 'competencies';

    protected $fillable = [
        'competency_type_id',
        'name_en',
        'name_id',
        'description_en',
        'description_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * A new master is usable straight away. Declared here as well as on the
     * column so a model created without the field still carries the value —
     * otherwise `is_active` would be unset in memory until the row is re-read.
     */
    protected $attributes = [
        'is_active' => true,
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
