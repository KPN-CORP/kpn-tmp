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
 * Planning rows are VERSIONED rather than unique per (employee, package): a set
 * that is revised and resubmitted opens a new row, so each revision keeps its
 * own chain and decisions. The current one is the latest row for the pair.
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
        'submitted_by',
        'submitted_at',
    ];

    protected $casts = [
        'layers' => 'array',
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
