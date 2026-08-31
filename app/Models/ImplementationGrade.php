<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One grade a master implementation is scoped to. The grade is the raw employee
 * `job_level` string from kpncorp, so it is a value rather than a foreign key.
 */
class ImplementationGrade extends Model
{
    public $timestamps = false;

    protected $fillable = ['implementation_id', 'grade'];

    public function implementation(): BelongsTo
    {
        return $this->belongsTo(CompetencyImplementation::class, 'implementation_id');
    }
}
