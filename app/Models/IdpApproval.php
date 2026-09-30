<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One staged approval workflow, at either stage of the IDP:
 *
 *  - `planning` — the employee's whole plan set for one development-model
 *    package, signed off once before any result may be submitted. Carries a
 *    `development_model_package_id` and no plan.
 *  - `result` — one plan row's realization + evidence. Carries the plan and no
 *    package.
 *
 * Either way approval walks `current_level` from 1 up the snapshotted `layers`
 * chain: approved only once the final layer signs off, rejected the moment any
 * layer declines. App-owned (mysql).
 *
 * Both stages are VERSIONED: a planning set (per employee + package) or a
 * result (per plan) that is resubmitted opens a new row, so each round keeps its
 * own chain and decisions. The current one is the latest row for its subject.
 *
 * `snapshot` freezes the plans the request covered at submission, so the log
 * shows each round as it was decided rather than as the plan reads today. Null
 * on rows submitted before snapshots were taken.
 */
class IdpApproval extends Model
{
    public const STAGE_PLANNING = 'planning';

    public const STAGE_RESULT = 'result';

    protected $connection = 'mysql';

    protected $table = 'idp_approvals';

    protected $fillable = [
        'stage',
        'individual_development_plan_id',
        'development_model_package_id',
        'employee_id',
        'status',
        'current_level',
        'layers',
        'snapshot',
        'submitted_by',
        'submitted_at',
    ];

    protected $casts = [
        'layers' => 'array',
        'snapshot' => 'array',
        'current_level' => 'integer',
        'submitted_at' => 'datetime',
    ];

    protected $attributes = [
        'stage' => self::STAGE_RESULT,
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(IndividualDevelopmentPlan::class, 'individual_development_plan_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(DevelopmentModelPackage::class, 'development_model_package_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(IdpApprovalStep::class, 'idp_approval_id')->orderBy('level');
    }

    public function scopePlanning(Builder $query): Builder
    {
        return $query->where('stage', self::STAGE_PLANNING);
    }

    public function scopeResult(Builder $query): Builder
    {
        return $query->where('stage', self::STAGE_RESULT);
    }

    public function isPlanning(): bool
    {
        return $this->stage === self::STAGE_PLANNING;
    }

    /**
     * The step whose turn it currently is (null once the chain is finished).
     */
    public function currentStep(): ?IdpApprovalStep
    {
        return $this->steps->firstWhere('level', $this->current_level);
    }

    /**
     * Number of approval layers in the snapshotted chain.
     */
    public function totalLevels(): int
    {
        return count($this->layers ?? []);
    }
}
