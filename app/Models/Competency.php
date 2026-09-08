<?php

namespace App\Models;

use App\Models\Concerns\HasActiveState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named competency, filed under a competency type, owning its own proficiency
 * ladder (and, under each rung, the key behaviors observed there), and linked to
 * the development programs that build it.
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

    /**
     * The parts this competency breaks down into, in the order they were added.
     * Edited inline on the competency form, so they cascade on delete.
     */
    public function subCompetencies(): HasMany
    {
        return $this->hasMany(SubCompetency::class)->orderBy('id');
    }

    /**
     * This competency's own proficiency ladder: free-typed, sequenced rows it
     * owns, each carrying its own key behaviors. This is what the competency
     * form edits — and, since the shared proficiency-level master was retired,
     * the only place a proficiency level comes from. Master Implementation,
     * Master Training and the development-program form all read it through the
     * competency they pick.
     */
    public function proficiencyLevels(): HasMany
    {
        return $this->hasMany(CompetencyProficiencyLevel::class)
            ->orderBy('sequence')
            ->orderBy('id');
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
