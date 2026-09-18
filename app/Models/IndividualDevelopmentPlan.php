<?php

namespace App\Models;

use Database\Factories\IndividualDevelopmentPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IndividualDevelopmentPlan extends Model
{
    /** @use HasFactory<IndividualDevelopmentPlanFactory> */
    use HasFactory;

    protected $connection = 'mysql';

    protected $table = 'individual_development_plans';

    protected $fillable = [
        'employee_id',
        'development_model_id',
        'competency_type',
        'competency_name',
        'review_tools',
        'development_program',
        'expected_outcome',
        'time_frame_start',
        'time_frame_end',
        'realization_date',
        'result_evidence',
        'planning_approved_at',
    ];

    protected $casts = [
        'time_frame_start' => 'date',
        'time_frame_end' => 'date',
        'realization_date' => 'date',
        'planning_approved_at' => 'datetime',
    ];

    /**
     * The planning fields — what the PLANNING stage approves. Changing any of
     * them un-approves the row (and sends its set back for re-approval);
     * `realization_date` / `result_evidence` belong to the result stage and are
     * deliberately not in here.
     */
    public const PLANNING_FIELDS = [
        'development_model_id',
        'competency_type',
        'competency_name',
        'review_tools',
        'development_program',
        'expected_outcome',
        'time_frame_start',
        'time_frame_end',
    ];

    public function developmentModel(): BelongsTo
    {
        return $this->belongsTo(DevelopmentModel::class, 'development_model_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    /**
     * This row's result-stage approval (one per plan; null until submitted).
     */
    public function resultApproval(): HasOne
    {
        return $this->hasOne(IdpApproval::class, 'individual_development_plan_id')
            ->where('stage', IdpApproval::STAGE_RESULT);
    }

    /**
     * Whether this row was part of an approved planning set — which is what
     * opens its result stage. Cleared whenever a planning field changes.
     */
    public function isPlanningApproved(): bool
    {
        return filled($this->planning_approved_at);
    }

    /**
     * The result has been filled in and is ready to be submitted for approval.
     */
    public function isRealized(): bool
    {
        return filled($this->realization_date) && filled($this->result_evidence);
    }
}
