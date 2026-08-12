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
}
