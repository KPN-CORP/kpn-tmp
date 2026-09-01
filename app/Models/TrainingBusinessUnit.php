<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business unit a master training is offered in. The unit is the raw
 * corporate grouping string from kpncorp (the employee `group_company` /
 * `locations.company_name`), so it is a value rather than a foreign key.
 */
class TrainingBusinessUnit extends Model
{
    public $timestamps = false;

    protected $fillable = ['training_id', 'business_unit'];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }
}
