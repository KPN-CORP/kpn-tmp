<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An observable behavior at one rung of a competency's own proficiency ladder:
 * a bilingual name and where it sits under that rung. Typed in on the
 * competency form under its level, and cascades with it.
 *
 * `sequence` is the row's position in that list, assigned from the submitted
 * order rather than typed in, so it is always 1..n with no gaps.
 */
class CompetencyKeyBehavior extends Model
{
    protected $table = 'competency_key_behaviors';

    protected $fillable = [
        'competency_proficiency_level_id',
        'name_en',
        'name_id',
        'sequence',
    ];

    protected $casts = [
        'sequence' => 'integer',
    ];

    public function proficiencyLevel(): BelongsTo
    {
        return $this->belongsTo(
            CompetencyProficiencyLevel::class,
            'competency_proficiency_level_id'
        );
    }
}
