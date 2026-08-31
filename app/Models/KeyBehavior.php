<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An observable behavior defined under one proficiency level. Names are unique
 * only within their level, so two levels may share a behavior name.
 */
class KeyBehavior extends Model
{
    protected $fillable = ['proficiency_level_id', 'name_en', 'name_id', 'description_en', 'description_id'];

    public function proficiencyLevel(): BelongsTo
    {
        return $this->belongsTo(ProficiencyLevel::class);
    }

    public function competencies(): BelongsToMany
    {
        return $this->belongsToMany(Competency::class);
    }
}
