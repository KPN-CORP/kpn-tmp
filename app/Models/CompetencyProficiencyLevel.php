<?php

namespace App\Models;

use App\Models\Concerns\HasActiveState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rung on a competency's own proficiency ladder: a bilingual name and
 * description, where it sits in the sequence, whether it is switched on, and
 * the key behaviors observed at that level.
 *
 * `sequence` is the rung's position on the competency form, assigned from the
 * submitted order rather than typed in, so it is always 1..n with no gaps.
 *
 * Owned by the competency and typed in on its form. Since the shared
 * `proficiency_levels` master was retired this is the only kind of proficiency
 * level there is: Master Implementation, Master Training and the
 * development-program form all select these, through the competency they pick.
 */
class CompetencyProficiencyLevel extends Model
{
    use HasActiveState;

    protected $table = 'competency_proficiency_levels';

    protected $fillable = [
        'competency_id',
        'name_en',
        'name_id',
        'description_en',
        'description_id',
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
     * The behaviors observed at this level, in the order they sit on the form.
     * Ownership: a behavior cannot outlive its level.
     */
    public function keyBehaviors(): HasMany
    {
        return $this->hasMany(CompetencyKeyBehavior::class, 'competency_proficiency_level_id')
            ->orderBy('sequence')
            ->orderBy('id');
    }

    /**
     * Master implementations that roll the competency out at this level.
     */
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
        return $this->belongsToMany(
            Training::class,
            'proficiency_level_training',
            'proficiency_level_id',
            'training_id',
        );
    }

    /**
     * Development programs that target this level.
     */
    public function developmentPrograms(): HasMany
    {
        return $this->hasMany(DevelopmentProgram::class, 'proficiency_level_id');
    }
}
