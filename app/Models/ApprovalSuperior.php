<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single employee's approval chain: an ordered list of superior approvers
 * (each a specific kpncorp employee_id), stored as a JSON array so the chain
 * can be any length. When no row exists, the chain defaults to the corporate
 * manager_l1 / manager_l2. App-owned (default mysql).
 */
class ApprovalSuperior extends Model
{
    protected $table = 'approval_superiors';

    protected $guarded = ['id'];

    protected $casts = [
        'layers' => 'array',
    ];

    /**
     * The configured approver employee_ids in order, dropping empty slots.
     *
     * @return list<string>
     */
    public function approverIds(): array
    {
        return collect($this->layers ?? [])
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->values()
            ->all();
    }
}
