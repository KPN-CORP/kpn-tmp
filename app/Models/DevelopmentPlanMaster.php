<?php

namespace App\Models;

use Database\Factories\DevelopmentPlanMasterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevelopmentPlanMaster extends Model
{
    /** @use HasFactory<DevelopmentPlanMasterFactory> */
    use HasFactory;

    protected $table = 'development_plan_masters';

    protected $guarded = ['id'];

    protected $casts = [
        'related_program' => 'array',
    ];

    public function developmentModel(): BelongsTo
    {
        return $this->belongsTo(DevelopmentModel::class, 'development_model_id');
    }

    /**
     * The competency type this competency (competency_name row) belongs to.
     * Self-referencing: the parent is a `competency_type` row in this table.
     */
    public function competencyType(): BelongsTo
    {
        return $this->belongsTo(self::class, 'competency_type_id');
    }
}
