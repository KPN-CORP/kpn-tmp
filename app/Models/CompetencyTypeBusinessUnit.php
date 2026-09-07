<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business unit a competency type applies to. The unit is the raw corporate
 * name from kpncorp's business-unit master ({@see BusinessUnit}) — the same
 * value `employees.group_company` holds — so it is a value rather than a
 * foreign key.
 */
class CompetencyTypeBusinessUnit extends Model
{
    public $timestamps = false;

    protected $fillable = ['competency_type_id', 'business_unit'];

    public function competencyType(): BelongsTo
    {
        return $this->belongsTo(CompetencyType::class);
    }
}
