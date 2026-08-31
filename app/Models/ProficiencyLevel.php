<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A proficiency level (e.g. L1/L2/L3). Owns the key behaviors defined under it,
 * and is separately selected by competencies, development programs and master
 * implementations.
 */
class ProficiencyLevel extends Model
{
    protected $fillable = ['name_en', 'name_id', 'description_en', 'description_id'];

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
}
