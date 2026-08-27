<?php

namespace App\Models;

use Database\Factories\DevelopmentPlanMasterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DevelopmentPlanMaster extends Model
{
    /** @use HasFactory<DevelopmentPlanMasterFactory> */
    use HasFactory;

    protected $table = 'development_plan_masters';

    protected $guarded = ['id'];

    protected $casts = [
        'related_program' => 'array',
        // A competency's multi-selected proficiency levels + key behaviors.
        'proficiency_level_ids' => 'array',
        'key_behavior_ids' => 'array',
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

    /**
     * The proficiency level chosen for this competency (competency_name row).
     * Self-referencing: the parent is a `proficiency_level` row in this table.
     */
    public function proficiencyLevel(): BelongsTo
    {
        return $this->belongsTo(self::class, 'proficiency_level_id');
    }

    /**
     * The key behavior pinned for this competency (competency_name row), if any.
     * Self-referencing: the parent is a `key_behavior` row under the chosen
     * proficiency level.
     */
    public function keyBehavior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'key_behavior_id');
    }

    /**
     * The key behaviors defined under this proficiency level (proficiency_level
     * row). Self-referencing: children are `key_behavior` rows that point back
     * via proficiency_level_id. One proficiency level has many key behaviors.
     */
    public function keyBehaviors(): HasMany
    {
        return $this->hasMany(self::class, 'proficiency_level_id')
            ->where('type', 'key_behavior');
    }

    /**
     * The competency implemented by this row (an `implementation` row).
     * Self-referencing: the parent is a `competency_name` row.
     */
    public function competencyName(): BelongsTo
    {
        return $this->belongsTo(self::class, 'competency_name_id');
    }
}
