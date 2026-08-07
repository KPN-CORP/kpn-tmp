<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in an approval flow. Its approver is resolved either dynamically
 * from the target employee's manager fields, or from a specifically chosen
 * employee. App-owned (default mysql connection).
 */
class ApprovalLayer extends Model
{
    protected $table = 'approval_layers';

    protected $guarded = ['id'];

    protected $casts = [
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];

    // Approver source types.
    public const TYPE_MANAGER_L1 = 'manager_l1';

    public const TYPE_MANAGER_L2 = 'manager_l2';

    public const TYPE_SPECIFIC = 'specific_employee';

    /** All valid approver_type values. */
    public const TYPES = [
        self::TYPE_MANAGER_L1,
        self::TYPE_MANAGER_L2,
        self::TYPE_SPECIFIC,
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    /**
     * Resolve the concrete approver's employee_id for a given target employee.
     * Dynamic types read the target's manager fields; the specific type returns
     * the configured employee. Returns null when the source field is empty.
     */
    public function resolveApproverId(Employee $employee): ?string
    {
        return match ($this->approver_type) {
            self::TYPE_MANAGER_L1 => $employee->manager_l1_id ?: null,
            self::TYPE_MANAGER_L2 => $employee->manager_l2_id ?: null,
            self::TYPE_SPECIFIC => $this->approver_employee_id ?: null,
            default => null,
        };
    }
}
