<?php

namespace App\Services\Idp;

use App\Models\DevelopmentModel;
use App\Models\DevelopmentModelPackage;
use App\Models\IdpApproval;
use App\Models\IndividualDevelopmentPlan;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Everything the two-stage IDP needs to know about WHERE a plan set currently
 * stands, and what that allows.
 *
 * The stages run:
 *
 *   1. Planning        — the plans are written (no result fields).
 *   2. Planning approval — the whole set is signed off ONCE, layer by layer.
 *   3. Result          — each program's realization date + evidence is filed.
 *   4. Result approval — each submitted result is signed off, layer by layer.
 *
 * A planning approval is scoped to one development-model package (the 70-20-10
 * cycle), so each cycle is planned and signed off on its own. Approvals are
 * versioned: a revised set opens a new row, and the latest row for the pair is
 * the current one.
 *
 * This service derives state and enforces the locks. The chain mechanics —
 * submitting, approving, notifying — live in IdpApprovalService.
 */
class IdpStageService
{
    /** The plan set has never been submitted. */
    public const DRAFT = 'draft';

    /** Submitted and working its way up the chain; the set is frozen. */
    public const PENDING = 'pending';

    /** Signed off by every layer; results may now be filed. */
    public const APPROVED = 'approved';

    /** A layer declined; the set is editable and can be resubmitted. */
    public const REJECTED = 'rejected';

    /**
     * Approved earlier, but the set has changed since — rows were added or
     * edited, so those rows need a fresh sign-off before their results open.
     */
    public const REVISION = 'revision';

    /**
     * The development-model package a plan is filed under (via its model), or
     * null for a plan whose model has been removed.
     */
    public function packageIdFor(IndividualDevelopmentPlan $plan): ?int
    {
        return DevelopmentModel::withTrashed()
            ->whereKey($plan->development_model_id)
            ->value('development_model_package_id');
    }

    /**
     * The model ids that belong to a package — the plans the package's planning
     * approval covers.
     *
     * @return list<int>
     */
    public function modelIdsFor(int $packageId): array
    {
        return DevelopmentModel::withTrashed()
            ->where('development_model_package_id', $packageId)
            ->pluck('id')
            ->all();
    }

    /**
     * Every plan of an employee filed under one package.
     *
     * @return Collection<int, IndividualDevelopmentPlan>
     */
    public function plansIn(string $employeeId, int $packageId): Collection
    {
        return IndividualDevelopmentPlan::where('employee_id', $employeeId)
            ->whereIn('development_model_id', $this->modelIdsFor($packageId) ?: [0])
            ->get();
    }

    /**
     * The current planning approval for a package — the latest revision, or
     * null when the set has never been submitted.
     */
    public function planningApproval(string $employeeId, ?int $packageId): ?IdpApproval
    {
        if ($packageId === null) {
            return null;
        }

        return IdpApproval::planning()
            ->where('employee_id', $employeeId)
            ->where('development_model_package_id', $packageId)
            ->with('steps')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Earlier planning revisions for a package, newest first — the sign-off
     * history behind the current one.
     *
     * @return Collection<int, IdpApproval>
     */
    public function planningHistory(string $employeeId, ?int $packageId, ?int $excludeId = null): Collection
    {
        if ($packageId === null) {
            return collect();
        }

        return IdpApproval::planning()
            ->where('employee_id', $employeeId)
            ->where('development_model_package_id', $packageId)
            ->when($excludeId, fn ($q, $id) => $q->whereKeyNot($id))
            ->with('steps')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Where the planning stage stands for a set of plans.
     *
     * `revision` is the one state that is not simply the approval's status: the
     * set was signed off, then rows were added or edited. Those rows carry no
     * `planning_approved_at`, so they cannot file results until the set is
     * approved again.
     *
     * @param  Collection<int, IndividualDevelopmentPlan>  $plans
     */
    public function planningStatus(?IdpApproval $approval, Collection $plans): string
    {
        if ($approval?->status === 'pending') {
            return self::PENDING;
        }

        $unapproved = $plans->reject->isPlanningApproved()->count();

        // The per-row stamp is the ground truth for what may proceed, so it
        // decides the headline too. That keeps the two from contradicting each
        // other in the case a rejected round leaves nothing outstanding — e.g.
        // an approved set gained one program, was rejected over it, and the
        // program was then dropped: every remaining row is still signed off.
        if ($unapproved === 0 && $plans->isNotEmpty()) {
            return self::APPROVED;
        }

        if ($approval?->status === 'approved') {
            return self::REVISION;
        }

        if ($approval?->status === 'rejected') {
            return self::REJECTED;
        }

        return self::DRAFT;
    }

    /**
     * Plans may only be added, edited or removed while the package's planning
     * approval is not in flight. Freezing the set while approvers are reading it
     * is the whole point of the stage — otherwise they would sign off something
     * that had since changed.
     */
    public function plansEditable(?IdpApproval $approval): bool
    {
        return $approval?->status !== 'pending';
    }

    /**
     * @throws ValidationException
     */
    public function assertPlansEditable(string $employeeId, ?int $packageId): void
    {
        if ($this->plansEditable($this->planningApproval($employeeId, $packageId))) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => 'This development plan is awaiting planning approval and cannot be changed. Ask the current approver to decide first, or wait for the outcome.',
        ]);
    }

    /**
     * A row whose result has been submitted and not declined is settled: its
     * planning fields can no longer be edited or the row deleted, because the
     * result signed off (or being signed off) refers to them.
     *
     * @throws ValidationException
     */
    public function assertResultNotSettled(IndividualDevelopmentPlan $plan): void
    {
        $status = $plan->resultApproval()->value('status');

        if (! in_array($status, ['pending', 'approved'], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'plan' => $status === 'pending'
                ? 'The result of this program is awaiting approval, so the program itself can no longer be changed.'
                : 'The result of this program has been approved, so the program itself can no longer be changed.',
        ]);
    }

    /**
     * Withdraw the planning sign-off for a WHOLE package: every row returns to
     * "not approved" and the set has to be submitted for approval again.
     *
     * Called whenever the set changes after it was approved — a program added,
     * edited or removed. The approver signed off on a specific set of programs,
     * so a different set is a different plan and needs a fresh decision. Doing
     * this per row instead would leave a plan half-approved, with the untouched
     * programs still filing results against an approval that no longer
     * describes the plan they sit in.
     *
     * Rows whose result is already pending or approved are exempt: an approver
     * has signed off on (or is signing off on) a result that refers to that row,
     * and the row is locked from editing anyway, so re-opening its planning
     * would misrepresent what was decided. Every row that COULD be changed
     * loses its sign-off; the ones that could not, keep it.
     *
     * @return int how many rows lost their sign-off
     */
    public function withdrawPlanningApproval(string $employeeId, ?int $packageId): int
    {
        if ($packageId === null) {
            return 0;
        }

        $modelIds = $this->modelIdsFor($packageId);

        if (empty($modelIds)) {
            return 0;
        }

        $settled = IdpApproval::result()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->pluck('individual_development_plan_id')
            ->filter()
            ->all();

        return IndividualDevelopmentPlan::where('employee_id', $employeeId)
            ->whereIn('development_model_id', $modelIds)
            ->whereNotNull('planning_approved_at')
            ->when($settled, fn ($q) => $q->whereNotIn('id', $settled))
            ->update(['planning_approved_at' => null]);
    }

    /**
     * The package whose plans the manage screen is currently working on — the
     * active one, falling back to the package the employee's plans actually sit
     * under when there is no active package at all.
     */
    public function currentPackage(): ?DevelopmentModelPackage
    {
        return DevelopmentModelPackage::active();
    }

    /**
     * Every cycle, newest first, shaped for a picker. Shared by the IDP list and
     * the manage screen so the two offer the same cycles in the same order.
     *
     * `$counts` maps a package id to a plan count. Passing it means every cycle
     * gets a number — one that is absent from the map genuinely holds zero —
     * while passing null means the screen has no count to show at all, and the
     * picker renders none rather than a misleading zero.
     *
     * @param  array<int, int>|null  $counts
     * @return Collection<int, array<string, mixed>>
     */
    public function packageOptions(?int $activePackageId, ?array $counts = null): Collection
    {
        return DevelopmentModelPackage::orderByDesc('start_date')->orderByDesc('id')->get()
            ->map(fn (DevelopmentModelPackage $package) => [
                'id' => $package->id,
                'name' => $package->name,
                'start_date' => $package->start_date?->toDateString(),
                'end_date' => $package->end_date?->toDateString(),
                'is_active' => $package->id === $activePackageId,
                'plans' => $counts === null ? null : ($counts[$package->id] ?? 0),
            ])
            ->values();
    }

    /**
     * Resolve the cycle a screen should show: the one asked for when it exists,
     * else the active one, else the newest there is.
     *
     * @param  Collection<int, array<string, mixed>>  $packages  from packageOptions()
     */
    public function selectedPackageId(Collection $packages, ?int $requested, ?int $activePackageId): ?int
    {
        if ($requested !== null && $packages->contains('id', $requested)) {
            return $requested;
        }

        if ($activePackageId !== null && $packages->contains('id', $activePackageId)) {
            return $activePackageId;
        }

        return $packages->first()['id'] ?? null;
    }
}
