<?php

namespace App\Services;

use App\Models\ApprovalSuperior;
use App\Models\Employee;

/**
 * Resolves an employee's effective approval chain — the ordered list of
 * superior approver employee_ids. A saved override (the Approval Layer screen)
 * wins; otherwise the chain defaults to the corporate manager_l1 / manager_l2.
 *
 * This is the single source of truth shared by the settings screen and the
 * approval runtime, so both compute the same chain.
 */
class ApprovalChainService
{
    /**
     * The ordered, de-duplicated approver employee_ids for an employee.
     *
     * @return list<string>
     */
    public function layersFor(string $employeeId): array
    {
        $override = ApprovalSuperior::where('employee_id', $employeeId)->first();

        if ($override && ! empty($override->approverIds())) {
            return $this->clean($override->approverIds());
        }

        try {
            $employee = Employee::where('employee_id', $employeeId)
                ->first(['employee_id', 'manager_l1_id', 'manager_l2_id']);
        } catch (\Throwable) {
            return [];
        }

        if (! $employee) {
            return [];
        }

        return $this->clean([$employee->manager_l1_id, $employee->manager_l2_id]);
    }

    /**
     * Whether anyone's effective chain names this employee as an approver —
     * i.e. whether they have subordinates at all.
     *
     * The mirror image of layersFor(), and it has to honour the same defaulting
     * rule: an explicit override wins, and only an employee WITHOUT one falls
     * back to the corporate manager fields. So being someone's manager_l1 does
     * not make them a subordinate if their chain has since been overridden to
     * someone else.
     *
     * The two sides live on different connections (approval_superiors on mysql,
     * employees on kpncorp), so this cannot be one joined query.
     */
    public function hasSubordinates(string $employeeId): bool
    {
        $employeeId = trim($employeeId);

        if ($employeeId === '') {
            return false;
        }

        // Named outright on someone's saved chain - no need to look further.
        if (ApprovalSuperior::whereJsonContains('layers', $employeeId)->exists()) {
            return true;
        }

        try {
            $reports = Employee::where(fn ($q) => $q
                ->where('manager_l1_id', $employeeId)
                ->orWhere('manager_l2_id', $employeeId))
                ->pluck('employee_id');
        } catch (\Throwable) {
            // kpncorp unreachable: fall back to what the app's own tables said,
            // which the check above already answered.
            return false;
        }

        if ($reports->isEmpty()) {
            return false;
        }

        // A corporate report only counts while it has no override of its own;
        // one that does is governed by that chain, which the check above showed
        // does not name this employee.
        $overridden = ApprovalSuperior::whereIn('employee_id', $reports)
            ->get(['employee_id', 'layers'])
            ->filter(fn (ApprovalSuperior $row) => $row->approverIds() !== [])
            ->pluck('employee_id');

        return $reports->diff($overridden)->isNotEmpty();
    }

    /**
     * @param  array<int, string|null>  $ids
     * @return list<string>
     */
    private function clean(array $ids): array
    {
        return collect($ids)
            ->map(fn ($id) => is_string($id) ? trim($id) : $id)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->unique()
            ->values()
            ->all();
    }
}
