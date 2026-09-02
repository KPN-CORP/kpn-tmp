<?php

namespace App\Models;

use App\Models\Concerns\HasActiveState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A proficiency level (e.g. L1/L2/L3). Owns the key behaviors defined under it,
 * and is separately selected by competencies, development programs and master
 * implementations. Optionally filed under one competency type; a level with no
 * type is global.
 */
class ProficiencyLevel extends Model
{
    use HasActiveState;

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

    /**
     * The competency type this level is filed under, if any.
     */
    public function competencyType(): BelongsTo
    {
        return $this->belongsTo(CompetencyType::class);
    }

    /**
     * The key behaviors defined under this level. This is ownership: a key
     * behavior cannot exist without its level.
     */
    public function keyBehaviors(): HasMany
    {
        return $this->hasMany(KeyBehavior::class);
    }

    /**
     * Competencies that selected this level. Distinct from the ownership above;
     * in the old single-table schema both meanings shared one column.
     */
    public function competencies(): BelongsToMany
    {
        return $this->belongsToMany(Competency::class);
    }

    public function developmentPrograms(): HasMany
    {
        return $this->hasMany(DevelopmentProgram::class);
    }

    public function implementations(): BelongsToMany
    {
        return $this->belongsToMany(
            CompetencyImplementation::class,
            'implementation_proficiency_level',
            'proficiency_level_id',
            'implementation_id',
        );
    }

    /**
     * Master trainings that target this level.
     */
    public function trainings(): BelongsToMany
    {
        return $this->belongsToMany(Training::class);
    }
}
