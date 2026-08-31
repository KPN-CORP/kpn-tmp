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
 * A program on the "Others" competency type free-types its competencies and
 * proficiency level (`custom_competency` / `custom_proficiency_level`) instead
 * of linking master rows.
 */
class DevelopmentProgram extends Model
{
    protected $fillable = [
        'development_model_id',
        'competency_type_id',
        'proficiency_level_id',
        'name_en',
        'name_id',
        'custom_competency',
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

    public function proficiencyLevel(): BelongsTo
    {
        return $this->belongsTo(ProficiencyLevel::class);
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
