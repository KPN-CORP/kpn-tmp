<?php

namespace App\Models;

use App\Models\Concerns\HasEffectivePeriod;
use Illuminate\Database\Eloquent\Model;

/**
 * A tool used to review an IDP item: a flat bilingual name list, each row
 * optionally scoped to an effective period.
 */
class ReviewTool extends Model
{
    use HasEffectivePeriod;

    protected $fillable = [
        'name_en',
        'name_id',
        'effective_start_date',
        'effective_end_date',
    ];

    protected $casts = [
        'effective_start_date' => 'date',
        'effective_end_date' => 'date',
    ];
}
