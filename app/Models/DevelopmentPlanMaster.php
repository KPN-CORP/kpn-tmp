<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevelopmentPlanMaster extends Model
{
    /** @use HasFactory<\Database\Factories\DevelopmentPlanMasterFactory> */
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
}
