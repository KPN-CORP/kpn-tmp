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
