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
     * Whether $approverId is the FIRST layer of $employeeId's effective chain —
     * the approver closest to the employee, who also looks after their plan.
     */
    public function isLayerOne(string $approverId, string $employeeId): bool
    {
        $approverId = trim($approverId);

        return $approverId !== '' && ($this->layersFor($employeeId)[0] ?? null) === $approverId;
    }

    /**
     * Every employee whose effective chain starts with $approverId: their
     * layer-1 reports. The mirror image of layersFor()[0], honouring the same
     * defaulting rule — a saved override decides; only an employee WITHOUT one
     * falls back to the corporate manager_l1_id.
     *
     * @return list<string>
     */
    public function layerOneReports(string $approverId): array
    {
        $approverId = trim($approverId);

        if ($approverId === '') {
            return [];
        }

        // Overrides that name them at all, kept where they come FIRST.
        $fromOverrides = ApprovalSuperior::whereJsonContains('layers', $approverId)
            ->get(['employee_id', 'layers'])
            ->filter(fn (ApprovalSuperior $row) => ($row->approverIds()[0] ?? null) === $approverId)
            ->pluck('employee_id');

        try {
            $corporate = Employee::where('manager_l1_id', $approverId)->pluck('employee_id');
        } catch (\Throwable) {
            // kpncorp unreachable: the overrides are all that can be known.
            return $fromOverrides->map(fn ($id) => (string) $id)->values()->all();
        }

        // A corporate report counts only while no override of its own decides
        // its chain (an override naming this approver first is already above).
        $overridden = $corporate->isEmpty()
            ? collect()
            : ApprovalSuperior::whereIn('employee_id', $corporate)
                ->get(['employee_id', 'layers'])
                ->filter(fn (ApprovalSuperior $row) => $row->approverIds() !== [])
                ->pluck('employee_id');

        return $fromOverrides
            ->merge($corporate->diff($overridden))
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
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
