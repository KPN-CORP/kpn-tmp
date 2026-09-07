<?php

namespace App\Models;

use App\Models\Concerns\HasActiveState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rung on a competency's own proficiency ladder: a bilingual name, where it
 * sits in the sequence, whether it is switched on, and the key behaviors
 * observed at that level.
 *
 * Owned by the competency and typed in on its form — not a row of the shared
 * `proficiency_levels` master, which other screens still select from. (Mind the
 * table names: `competency_proficiency_levels` here, plural, versus the old
 * `competency_proficiency_level` pivot at the master.)
 */
class CompetencyProficiencyLevel extends Model
{
    use HasActiveState;

    protected $table = 'competency_proficiency_levels';

    protected $fillable = [
        'competency_id',
        'name_en',
        'name_id',
        'sequence',
        'is_active',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * A new level is usable straight away. Declared here as well as on the
     * column so a model created without the field still carries the value —
     * otherwise `is_active` would be unset in memory until the row is re-read.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    /**
     * The behaviors observed at this level, in the order they were added.
     * Ownership: a behavior cannot outlive its level.
     */
    public function keyBehaviors(): HasMany
    {
        return $this->hasMany(CompetencyKeyBehavior::class, 'competency_proficiency_level_id')
            ->orderBy('id');
    }
}
