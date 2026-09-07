<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An observable behavior at one rung of a competency's own proficiency ladder:
 * a bilingual name, nothing else. Typed in on the competency form under its
 * level, and cascades with it.
 *
 * Distinct from the shared `key_behaviors` master, which belongs to a
 * `proficiency_levels` row and is still selected by other screens.
 */
class CompetencyKeyBehavior extends Model
{
    protected $table = 'competency_key_behaviors';

    protected $fillable = [
        'competency_proficiency_level_id',
        'name_en',
        'name_id',
    ];

    public function proficiencyLevel(): BelongsTo
    {
        return $this->belongsTo(
            CompetencyProficiencyLevel::class,
            'competency_proficiency_level_id'
        );
    }
}
