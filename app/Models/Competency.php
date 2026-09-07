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
     * form edits.
     *
     * Not to be confused with {@see masterProficiencyLevels()} below, which is
     * the older selection of shared master rows.
     */
    public function proficiencyLevels(): HasMany
    {
        return $this->hasMany(CompetencyProficiencyLevel::class)
            ->orderBy('sequence')
            ->orderBy('id');
    }

    /**
     * Proficiency levels picked from the shared master, through the
     * `competency_proficiency_level` pivot.
     *
     * Legacy: the competency form owns its levels now and no longer writes this
     * pivot. Master Implementation and the development-program screen still
     * read it, so the links that exist are left alone.
     */
    public function masterProficiencyLevels(): BelongsToMany
    {
        return $this->belongsToMany(ProficiencyLevel::class, 'competency_proficiency_level');
    }

    /**
     * Key behaviors picked from the shared master. Legacy, like
     * {@see masterProficiencyLevels()}.
     */
    public function masterKeyBehaviors(): BelongsToMany
    {
        return $this->belongsToMany(KeyBehavior::class, 'competency_key_behavior');
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
