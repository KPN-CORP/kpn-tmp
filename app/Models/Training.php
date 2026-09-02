<?php

namespace App\Models;

use App\Models\Concerns\HasActiveState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A training in the master training catalogue: a bilingual name + description,
 * scoped to the competency it builds (through its competency type) at any
 * number of proficiency levels, and to the corporate business units / work
 * locations it is offered in. Every part of the scope is optional.
 *
 * A training can be switched off without being deleted; who flipped it is
 * recorded outside the database.
 */
class Training extends Model
{
    use HasActiveState;

    protected $fillable = [
        'competency_type_id',
        'competency_id',
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
     * A new training is usable straight away. Declared here as well as on the
     * column so a model created without the field still carries the value.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The type that scopes which competency this training may build.
     */
    public function competencyType(): BelongsTo
    {
        return $this->belongsTo(CompetencyType::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    /**
     * The proficiency levels this training targets. An empty list means it is
     * not pinned to any.
     */
    public function proficiencyLevels(): BelongsToMany
    {
        return $this->belongsToMany(ProficiencyLevel::class);
    }

    /**
     * The business units this training is offered in. An empty list means it is
     * not scoped to any.
     */
    public function businessUnits(): HasMany
    {
        return $this->hasMany(TrainingBusinessUnit::class);
    }

    /**
     * The work locations this training is offered at, always sites of the
     * business units above.
     */
    public function workLocations(): HasMany
    {
        return $this->hasMany(TrainingWorkLocation::class);
    }

    /**
     * Development programs that took their name from this training.
     */
    public function developmentPrograms(): HasMany
    {
        return $this->hasMany(DevelopmentProgram::class);
    }
}
