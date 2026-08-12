<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The approval workflow header for one IDP item. Staged approval walks
 * `current_level` from 1 up the snapshotted `layers` chain; the item is
 * `approved` only once the final layer signs off, or `rejected` the moment any
 * layer declines. App-owned (mysql).
 */
class IdpApproval extends Model
{
    protected $connection = 'mysql';

    protected $table = 'idp_approvals';

    protected $fillable = [
        'individual_development_plan_id',
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(IndividualDevelopmentPlan::class, 'individual_development_plan_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(IdpApprovalStep::class, 'idp_approval_id')->orderBy('level');
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
