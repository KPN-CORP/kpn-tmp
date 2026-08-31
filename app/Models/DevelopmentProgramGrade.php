<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One grade a development program is scoped to. The grade is the raw employee
 * `job_level` string from kpncorp, so it is a value rather than a foreign key.
 */
class DevelopmentProgramGrade extends Model
{
    public $timestamps = false;

    protected $fillable = ['development_program_id', 'grade'];

    public function developmentProgram(): BelongsTo
    {
        return $this->belongsTo(DevelopmentProgram::class);
    }
}
