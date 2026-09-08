<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A development activity filed under a weighted development model (the 70-20-10
 * split). Program names are long free text, reading as activity descriptions,
 * so `name_en` / `name_id` are TEXT rather than short strings.
 *
 * Every program develops one competency master, picked from the competencies
 * filed under its competency type — the catch-all "Others" type included. What
 * a program on "Others" still free-types is its proficiency level
 * (`custom_proficiency_level`): that type has no implementation map to draw a
 * level from.
 *
 * The name and description are either typed here or taken from the Master
 * Training catalogue — which of the two is decided by the development model the
 * program is filed under (`development_models.uses_master_training`), not by
 * the program itself. `training_id` records where they came from — it is null
 * for a typed name — while `name_en` / `name_id` and `description_en` /
 * `description_id` stay the single place they are read from, copied off the
 * training on save.
 */
class DevelopmentProgram extends Model
{
    protected $fillable = [
        'development_model_id',
        'competency_type_id',
        'training_id',
        'proficiency_level_id',
        'name_en',
        'name_id',
        'description_en',
        'description_id',
        'custom_proficiency_level',
    ];

    public function developmentModel(): BelongsTo
    {
        return $this->belongsTo(DevelopmentModel::class);
    }

    public function competencyType(): BelongsTo
    {
        return $this->belongsTo(CompetencyType::class);
    }

    /**
     * The rung of its competency's ladder this program targets — reached
     * through the master-implementation map, which is what says where a
     * competency is rolled out.
     */
    public function proficiencyLevel(): BelongsTo
    {
        return $this->belongsTo(CompetencyProficiencyLevel::class, 'proficiency_level_id');
    }

    /**
     * The training this program's name was taken from, or null when it was
     * typed by hand.
     */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function competencies(): BelongsToMany
    {
        return $this->belongsToMany(Competency::class);
    }

    /**
     * The grades (employee `job_level`) this program is scoped to. An empty list
     * means it applies to every grade.
     */
    public function grades(): HasMany
    {
        return $this->hasMany(DevelopmentProgramGrade::class);
    }
}
