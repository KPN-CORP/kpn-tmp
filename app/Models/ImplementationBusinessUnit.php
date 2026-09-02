<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business unit a master implementation covers. The unit is the raw
 * corporate grouping string from kpncorp (the employee `group_company`), so it
 * is a value rather than a foreign key.
 */
class ImplementationBusinessUnit extends Model
{
    public $timestamps = false;

    protected $fillable = ['implementation_id', 'business_unit'];

    public function implementation(): BelongsTo
    {
        return $this->belongsTo(CompetencyImplementation::class, 'implementation_id');
    }
}
