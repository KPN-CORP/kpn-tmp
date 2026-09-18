<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single layer of an IDP item's approval chain, carrying that approver's
 * decision and the note they left. App-owned (mysql).
 */
class IdpApprovalStep extends Model
{
    /**
     * The note a layer carries when it was signed off implicitly, because the
     * person submitting the request is themselves an approver on the chain.
     * A constant so the history can tell such a step from a real decision
     * without matching on prose.
     */
    public const AUTO_NOTE = 'Auto-approved on submission (submitted by this approver).';

    protected $connection = 'mysql';

    protected $table = 'idp_approval_steps';

    protected $fillable = [
        'idp_approval_id',
        'level',
        'approver_employee_id',
        'status',
        'note',
        'acted_by',
        'acted_by_name',
        'acted_at',
    ];

    protected $casts = [
        'level' => 'integer',
        'acted_at' => 'datetime',
    ];

    public function approval(): BelongsTo
    {
        return $this->belongsTo(IdpApproval::class, 'idp_approval_id');
    }

    /**
     * Signed off implicitly on submission rather than actually decided.
     */
    public function isAutoApproved(): bool
    {
        return $this->status === 'approved' && $this->note === self::AUTO_NOTE;
    }
}
