<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A classification for competencies (e.g. Soft Competency, Technical
 * Competency, Others). Competencies, development programs and master
 * implementations are all filed under one.
 *
 * `code` is a short unique identifier for the type, alongside its bilingual
 * name. It is nullable for the types that predate the column; the form
 * requires one, so a type picks its code up on its next save.
 */
class CompetencyType extends Model
{
    protected $fillable = ['code', 'name_en', 'name_id', 'description_en', 'description_id'];

    public function competencies(): HasMany
    {
        return $this->hasMany(Competency::class);
    }

    /**
     * The business units this type applies to — raw corporate names from
     * kpncorp's business-unit master ({@see BusinessUnit}).
     */
    public function businessUnits(): HasMany
    {
        return $this->hasMany(CompetencyTypeBusinessUnit::class);
    }

    public function developmentPrograms(): HasMany
    {
        return $this->hasMany(DevelopmentProgram::class);
    }

    public function implementations(): HasMany
    {
        return $this->hasMany(CompetencyImplementation::class);
    }

    /**
     * Master trainings scoped to this type.
     */
    public function trainings(): HasMany
    {
        return $this->hasMany(Training::class);
    }

    /**
     * The catch-all "Others" type. A development program on this type free-types
     * its competencies and proficiency level instead of picking them from the
     * masters.
     */
    public function isOthers(): bool
    {
        return in_array(strtolower(trim((string) $this->name_en)), ['others', 'other', 'lainnya'], true);
    }
}
