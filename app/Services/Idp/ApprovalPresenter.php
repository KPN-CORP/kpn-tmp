<?php

namespace App\Services\Idp;

use App\Models\Employee;
use App\Models\IdpApproval;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Shapes an approval workflow for the wire, in the one shape every screen
 * reads: the manage page's stage tracker, the per-program result row, and the
 * approver's inbox.
 *
 * Approver names live in the corporate DB, so they are resolved in one guarded
 * batch up front (`prime()`) rather than per step.
 */
class ApprovalPresenter
{
    /** @var array<string, string> employee_id => fullname */
    private array $names = [];

    /**
     * Resolve every approver / owner name the caller is about to render, in one
     * query. Safe to call repeatedly; ids already known are not re-fetched.
     *
     * @param  iterable<int, string|null>  $employeeIds
     */
    public function prime(iterable $employeeIds): self
    {
        $missing = collect($employeeIds)
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->reject(fn (string $id) => array_key_exists($id, $this->names))
            ->values();

        if ($missing->isEmpty()) {
            return $this;
        }

        try {
            $found = Employee::whereIn('employee_id', $missing)->pluck('fullname', 'employee_id')->all();
        } catch (\Throwable) {
            $found = [];
        }

        // An approver need not appear in the corporate master — a chain can name
        // anyone, and the master is not always complete. Their app account is the
        // next best source of a name; only then does the bare id show.
        $unresolved = $missing->reject(fn (string $id) => filled($found[$id] ?? null))->values();

        if ($unresolved->isNotEmpty()) {
            $accounts = User::whereIn('employee_id', $unresolved)->pluck('name', 'employee_id')->all();
            $found += array_filter($accounts, fn ($name) => filled($name));
        }

        foreach ($missing as $id) {
            $this->names[$id] = $found[$id] ?? $id;
        }

        return $this;
    }

    public function name(?string $employeeId): ?string
    {
        if (blank($employeeId)) {
            return null;
        }

        return $this->names[$employeeId] ?? $employeeId;
    }

    /**
     * Every approver id referenced by a set of approvals, for priming.
     *
     * @param  Collection<int, IdpApproval>  $approvals
     * @return Collection<int, string>
     */
    public function approverIds(Collection $approvals): Collection
    {
        return $approvals
            ->flatMap(fn (IdpApproval $approval) => $approval->steps->pluck('approver_employee_id'))
            ->merge($approvals->pluck('employee_id'))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function approval(IdpApproval $approval, ?string $viewerEmployeeId = null): array
    {
        $current = $approval->currentStep();

        return [
            'id' => $approval->id,
            'stage' => $approval->stage,
            'status' => $approval->status,
            'current_level' => $approval->current_level,
            'total_levels' => $approval->totalLevels(),
            'submitted_at' => $approval->submitted_at?->toDateTimeString(),
            'steps' => $approval->steps->map(fn ($step) => $this->step($step))->values()->all(),
            'can_act' => filled($viewerEmployeeId)
                && $approval->status === 'pending'
                && $current?->approver_employee_id === $viewerEmployeeId,
        ];
    }

    /**
     * The chain an unsubmitted request WOULD follow, so the UI can show where it
     * will go before anyone has acted.
     *
     * @param  list<string>  $layers
     * @return array<string, mixed>
     */
    public function preview(array $layers): array
    {
        return [
            'id' => null,
            'stage' => null,
            'status' => 'draft',
            'current_level' => null,
            'total_levels' => count($layers),
            'submitted_at' => null,
            'steps' => collect($layers)->values()->map(fn ($id, $i) => [
                'level' => $i + 1,
                'approver_id' => $id,
                'approver_name' => $this->name($id),
                'status' => 'pending',
                'note' => null,
                'acted_by_name' => null,
                'acted_at' => null,
            ])->all(),
            'can_act' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function step($step): array
    {
        return [
            'level' => $step->level,
            'approver_id' => $step->approver_employee_id,
            'approver_name' => $this->name($step->approver_employee_id),
            'status' => $step->status,
            'note' => $step->note,
            'acted_by_name' => $step->acted_by_name,
            'acted_at' => $step->acted_at?->toDateTimeString(),
        ];
    }
}
