<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One work location a master training is offered at. The location is the raw
 * corporate site name from kpncorp (`locations.area`), so it is a value rather
 * than a foreign key.
 */
class TrainingWorkLocation extends Model
{
    public $timestamps = false;

    protected $fillable = ['training_id', 'work_location'];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }
}
