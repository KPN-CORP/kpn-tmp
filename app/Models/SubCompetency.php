<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One part a competency breaks down into: a bilingual name plus an optional
 * bilingual description.
 *
 * Not a master of its own — it has no screen, no active flag and no type. Rows
 * are added and removed inline on the competency form and cascade with their
 * parent.
 */
class SubCompetency extends Model
{
    protected $fillable = [
        'competency_id',
        'name_en',
        'name_id',
        'description_en',
        'description_id',
    ];

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
