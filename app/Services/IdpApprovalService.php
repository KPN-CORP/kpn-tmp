<?php

namespace App\Services;

use App\Mail\ApprovalMail;
use App\Models\ApprovalNotification;
use App\Models\Employee;
use App\Models\IdpApproval;
use App\Models\IdpApprovalStep;
use App\Models\IndividualDevelopmentPlan;
use App\Models\User;
use App\Services\Idp\ApprovalSnapshot;
use App\Services\Idp\IdpStageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The approval runtime for both IDP stages.
 *
 *  - PLANNING: the employee's whole plan set for one development-model package
 *    is submitted once and walks the chain once. Approving the final layer
 *    stamps every plan in the set as planning-approved, which is what opens the
 *    result stage.
 *  - RESULT: each program's realization + evidence is submitted on its own and
 *    walks the chain on its own.
 *
 * Either way, submitting snapshots the employee's approval chain and opens a
 * staged workflow (L1 → L2 → …). Only the approver whose turn it currently is
 * may act; each decision carries a required note. Every transition raises an
 * in-app notification and an email: a "need approval" alert to the next
 * approver, and the outcome back to the submitter and the IDP owner.
 */
class IdpApprovalService
{
    public function __construct(
        private readonly ApprovalChainService $chain,
        private readonly IdpStageService $stage,
    ) {}

    // ---------------------------------------------------------------- planning

    /**
     * Submit an employee's whole plan set for one package for planning
     * approval. A revised set opens a NEW approval, so each round keeps its own
     * chain and decisions.
     *
     * @throws ValidationException when the stage cannot be opened.
     */
    public function submitPlanning(string $employeeId, int $packageId, User $user): IdpApproval
    {
        $layers = $this->requireLayers($employeeId);

        // Every check runs under the plan-set lock, so a double submit (or a
        // plan edited mid-submit) waits here and then sees the other's outcome
        // instead of opening a second round.
        return $this->stage->withPlanSetLocked($employeeId, $packageId, function (Collection $plans) use ($employeeId, $packageId, $user, $layers) {
            $pending = IdpApproval::planning()
                ->where('employee_id', $employeeId)
                ->where('development_model_package_id', $packageId)
                ->where('status', 'pending')
                ->exists();

            if ($pending) {
                throw ValidationException::withMessages([
                    'approval' => 'This development plan is already awaiting planning approval.',
                ]);
            }

            if ($plans->isEmpty()) {
                throw ValidationException::withMessages([
                    'approval' => 'Add at least one development plan before submitting the planning for approval.',
                ]);
            }

            // Nothing to approve: every row already carries a sign-off. Keyed on
            // the rows rather than the header, for the same reason
            // planningStatus() is.
            if ($plans->every->isPlanningApproved()) {
                throw ValidationException::withMessages([
                    'approval' => 'This development plan has already been approved and nothing has changed since.',
                ]);
            }

            $approval = IdpApproval::create([
                'stage' => IdpApproval::STAGE_PLANNING,
                'individual_development_plan_id' => null,
                'development_model_package_id' => $packageId,
                'employee_id' => $employeeId,
                'status' => 'pending',
                'current_level' => 1,
                'layers' => $layers,
                'snapshot' => ApprovalSnapshot::of($plans),
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]);

            return $this->startChain($approval, $user, $layers);
        });
    }

    // ------------------------------------------------------------------ result

    /**
     * Submit one program's result for approval. The row must already be part of
     * an approved planning set, and must carry its realization date + evidence.
     *
     * @throws ValidationException
     */
    public function submitResult(IndividualDevelopmentPlan $plan, User $user): IdpApproval
    {
        $layers = $this->requireLayers($plan->employee_id);

        return DB::transaction(function () use ($plan, $user, $layers) {
            // Lock the program's row first: a double submit waits here and then
            // finds the round the first one opened. Being the transaction's
            // first read, the lock also fixes its snapshot AFTER the wait, so
            // the checks below see what the other request committed.
            $plan = IndividualDevelopmentPlan::whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            // The current round - a resubmitted result opens a new one.
            $existing = IdpApproval::result()
                ->where('individual_development_plan_id', $plan->id)
                ->latest('id')
                ->first();

            if ($existing && $existing->status === 'pending') {
                throw ValidationException::withMessages([
                    'approval' => 'The result of this program is already awaiting approval.',
                ]);
            }

            if ($existing && $existing->status === 'approved') {
                throw ValidationException::withMessages([
                    'approval' => 'The result of this program has already been approved.',
                ]);
            }

            if (! $plan->isPlanningApproved()) {
                throw ValidationException::withMessages([
                    'approval' => 'The planning for this program has not been approved yet, so its result cannot be submitted.',
                ]);
            }

            if (! $plan->isRealized()) {
                throw ValidationException::withMessages([
                    'approval' => 'Fill in the realization date and the result evidence before submitting this result.',
                ]);
            }

            // A NEW row per round, never an overwrite: a rejected result that is
            // filed again keeps the earlier round, its decisions and what it
            // said, so the log can show both and what changed between them.
            $approval = IdpApproval::create([
                'stage' => IdpApproval::STAGE_RESULT,
                'individual_development_plan_id' => $plan->id,
                'development_model_package_id' => null,
                'employee_id' => $plan->employee_id,
                'status' => 'pending',
                'current_level' => 1,
                'layers' => $layers,
                'snapshot' => ApprovalSnapshot::of(collect([$plan])),
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]);

            return $this->startChain($approval, $user, $layers);
        });
    }

    // ------------------------------------------------------------- the chain

    /**
     * Lay out the steps for a freshly opened workflow and hand it to its first
     * approver — or straight past the submitter's own layers, if they are in
     * the chain themselves.
     *
     * @param  list<string>  $layers
     */
    private function startChain(IdpApproval $approval, User $user, array $layers): IdpApproval
    {
        foreach ($layers as $index => $approverId) {
            IdpApprovalStep::create([
                'idp_approval_id' => $approval->id,
                'level' => $index + 1,
                'approver_employee_id' => $approverId,
                'status' => 'pending',
            ]);
        }

        $approval->load('steps');

        // Auto-approve the submitter's own layer (and any earlier layers). If
        // the person submitting is themselves an approver in the chain — e.g. L1
        // submitting for their own team member — their approval is implicit, so
        // the workflow skips straight to the next layer.
        $submitterIndex = filled($user->employee_id)
            ? array_search($user->employee_id, $layers, true)
            : false;

        if ($submitterIndex !== false) {
            return $this->autoApproveThrough($approval, $user, $submitterIndex + 1);
        }

        $this->notifyApprover($approval, 1);

        return $approval;
    }

    /**
     * Mark every layer up to and including $throughLevel as auto-approved on the
     * submitter's behalf, then either finish the workflow (submitter was the
     * final layer) or hand off to the next pending approver.
     */
    private function autoApproveThrough(IdpApproval $approval, User $user, int $throughLevel): IdpApproval
    {
        foreach ($approval->steps as $step) {
            if ($step->level <= $throughLevel && $step->status === 'pending') {
                $step->update([
                    'status' => 'approved',
                    'note' => IdpApprovalStep::AUTO_NOTE,
                    'acted_by' => $user->id,
                    'acted_by_name' => $user->name,
                    'acted_at' => now(),
                ]);
            }
        }

        if ($throughLevel >= $approval->totalLevels()) {
            // The submitter is the final approver — nothing left to sign off.
            $approval->update(['current_level' => $throughLevel, 'status' => 'approved']);
            $approval->load('steps');
            $this->finalizeApproved($approval);
            $this->notifyOutcome($approval, 'approval_approved');

            return $approval;
        }

        $approval->update(['current_level' => $throughLevel + 1]);
        $approval->load('steps');
        $this->notifyApprover($approval, $throughLevel + 1);

        return $approval;
    }

    /**
     * Record the current approver's approval. Advances to the next layer, or
     * finishes the workflow when the final layer signs off.
     *
     * @throws ValidationException
     */
    public function approve(IdpApproval $approval, User $user, string $note): IdpApproval
    {
        return DB::transaction(function () use ($approval, $user, $note) {
            $approval = $this->lockForDecision($approval);
            $step = $this->guardCurrentApprover($approval, $user);

            $step->update([
                'status' => 'approved',
                'note' => $note,
                'acted_by' => $user->id,
                'acted_by_name' => $user->name,
                'acted_at' => now(),
            ]);

            if ($approval->current_level < $approval->totalLevels()) {
                $approval->update(['current_level' => $approval->current_level + 1]);
                $approval->load('steps');
                // Next layer's turn — send it a "need approval" alert.
                $this->notifyApprover($approval, $approval->current_level);
            } else {
                $approval->update(['status' => 'approved']);
                $approval->load('steps');
                $this->finalizeApproved($approval);
                $this->notifyOutcome($approval, 'approval_approved');
            }

            return $approval->refresh();
        });
    }

    /**
     * Record the current approver's rejection. The chain stops; the owner can
     * revise and resubmit from L1.
     *
     * @throws ValidationException
     */
    public function reject(IdpApproval $approval, User $user, string $note): IdpApproval
    {
        return DB::transaction(function () use ($approval, $user, $note) {
            $approval = $this->lockForDecision($approval);
            $step = $this->guardCurrentApprover($approval, $user);

            $step->update([
                'status' => 'rejected',
                'note' => $note,
                'acted_by' => $user->id,
                'acted_by_name' => $user->name,
                'acted_at' => now(),
            ]);

            $approval->update(['status' => 'rejected']);
            $approval->load('steps');
            $this->notifyOutcome($approval, 'approval_rejected');

            return $approval->refresh();
        });
    }

    /**
     * What a fully approved workflow does beyond flipping its own status: a
     * planning approval stamps every plan in its package as planning-approved,
     * which is what opens the result stage for those rows.
     */
    private function finalizeApproved(IdpApproval $approval): void
    {
        if (! $approval->isPlanning() || ! $approval->development_model_package_id) {
            return;
        }

        $modelIds = $this->stage->modelIdsFor($approval->development_model_package_id);

        if (empty($modelIds)) {
            return;
        }

        IndividualDevelopmentPlan::where('employee_id', $approval->employee_id)
            ->whereIn('development_model_id', $modelIds)
            ->whereNull('planning_approved_at')
            ->update(['planning_approved_at' => now()]);
    }

    /**
     * The employee's approval chain, refused when there is none configured.
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function requireLayers(string $employeeId): array
    {
        $layers = $this->chain->layersFor($employeeId);

        if (empty($layers)) {
            throw ValidationException::withMessages([
                'approval' => 'No approval superiors are configured for this employee. Set them on the Approval Layer screen first.',
            ]);
        }

        return $layers;
    }

    /**
     * Everything on a given user's approval desk: their own still-undecided
     * step on every still-pending workflow they sit on — at ANY layer, not
     * only the one whose turn it is.
     *
     * Seeing a request before it reaches you is the point. An L2 approver can
     * read the plan (or the result) while L1 is still holding it, so the work
     * is not a surprise when it lands. Whether they may act on it yet is a
     * separate question, answered by actionableFor() — and enforced, whatever
     * the UI offers, by guardCurrentApprover().
     *
     * @return Collection<int, IdpApprovalStep>
     */
    public function pendingFor(User $user): Collection
    {
        if (blank($user->employee_id)) {
            return collect();
        }

        return IdpApprovalStep::query()
            ->where('approver_employee_id', $user->employee_id)
            ->where('status', 'pending')
            ->with(['approval.plan', 'approval.package', 'approval.steps'])
            ->get()
            ->filter(fn (IdpApprovalStep $step) => $step->approval
                && $step->approval->status === 'pending')
            ->values();
    }

    /** How big the desk is — everything visible, at every layer. */
    public function pendingCountFor(User $user): int
    {
        return $this->pendingStepQuery($user)?->count() ?? 0;
    }

    /**
     * pendingFor() as a query, for counting without hydrating the approvals,
     * their plans and their chains. Null when the user has no employee id (and
     * so cannot sit on any chain).
     */
    private function pendingStepQuery(User $user): ?Builder
    {
        if (blank($user->employee_id)) {
            return null;
        }

        // Qualified: pendingStageCountsFor() joins idp_approvals, which has a
        // `status` of its own.
        return IdpApprovalStep::query()
            ->where('idp_approval_steps.approver_employee_id', $user->employee_id)
            ->where('idp_approval_steps.status', 'pending')
            ->whereHas('approval', fn (Builder $q) => $q->where('idp_approvals.status', 'pending'));
    }

    /**
     * Pending requests on the desk per stage (planning / result), counted in SQL.
     *
     * @return array{planning: int, result: int}
     */
    public function pendingStageCountsFor(User $user): array
    {
        $counts = $this->pendingStepQuery($user)
            ?->join('idp_approvals', 'idp_approvals.id', '=', 'idp_approval_steps.idp_approval_id')
            ->groupBy('idp_approvals.stage')
            ->selectRaw('idp_approvals.stage, count(*) as total')
            ->pluck('total', 'stage')
            ?? collect();

        return [
            'planning' => (int) ($counts[IdpApproval::STAGE_PLANNING] ?? 0),
            'result' => (int) ($counts[IdpApproval::STAGE_RESULT] ?? 0),
        ];
    }

    /**
     * The part of the desk this user may decide right now: the requests whose
     * current layer is theirs. This is what "you have N approvals to do" means.
     *
     * @return Collection<int, IdpApprovalStep>
     */
    public function actionableFor(User $user): Collection
    {
        return $this->pendingFor($user)
            ->filter(fn (IdpApprovalStep $step) => $step->approval->current_level === $step->level)
            ->values();
    }

    /**
     * How many requests this user may decide right now — the shell's badge, read
     * on every page, so it is one COUNT rather than actionableFor()->count().
     */
    public function actionableCountFor(User $user): int
    {
        return $this->pendingStepQuery($user)
            ?->whereHas('approval', fn (Builder $q) => $q
                ->whereColumn('idp_approvals.current_level', 'idp_approval_steps.level'))
            ->count() ?? 0;
    }

    /**
     * The owner's side of the desk: their OWN requests an approver rejected —
     * a plan set or a program's result — that are waiting to be revised and
     * submitted again.
     *
     * A rejection is a task while it is the LATEST round of its subject (same
     * employee + package for a plan, same program for a result); resubmitting
     * opens a new round, which is what moves it to the owner's history. Only
     * the active cycle, since no screen reaches a closed one.
     *
     * @return Collection<int, IdpApproval>
     */
    public function revisionsFor(User $user): Collection
    {
        return $this->revisionQuery($user, resolved: false)
            ?->with(['plan', 'package', 'steps'])
            ->get() ?? collect();
    }

    /**
     * Rejections the owner has since revised and resubmitted — their side of the
     * history. Each is paired with the round that answered it (`answeredBy`).
     *
     * @return Collection<int, IdpApproval>
     */
    public function revisedBy(User $user): Collection
    {
        $rejected = $this->revisionQuery($user, resolved: true)
            ?->with(['plan', 'package', 'steps'])
            ->get() ?? collect();

        if ($rejected->isEmpty()) {
            return $rejected;
        }

        // The round that followed each rejection: the next one for the same subject.
        $later = IdpApproval::query()
            ->where('employee_id', $user->employee_id)
            ->where('id', '>', $rejected->min('id'))
            ->orderBy('id')
            ->get();

        return $rejected->each(function (IdpApproval $approval) use ($later) {
            $approval->setRelation('answeredBy', $later->first(fn (IdpApproval $next) => $next->id > $approval->id
                && $next->stage === $approval->stage
                && ($approval->isPlanning()
                    ? $next->development_model_package_id === $approval->development_model_package_id
                    : $next->individual_development_plan_id === $approval->individual_development_plan_id)));
        });
    }

    /** How many rejections this user has to revise — part of the menu badge. */
    public function revisionCountFor(User $user): int
    {
        return $this->revisionQuery($user, resolved: false)?->count() ?? 0;
    }

    public function revisedCountFor(User $user): int
    {
        return $this->revisionQuery($user, resolved: true)?->count() ?? 0;
    }

    /** @return array{planning: int, result: int} */
    public function revisionStageCountsFor(User $user): array
    {
        $counts = $this->revisionQuery($user, resolved: false)
            ?->groupBy('idp_approvals.stage')
            ->selectRaw('idp_approvals.stage, count(*) as total')
            ->pluck('total', 'stage')
            ?? collect();

        return [
            'planning' => (int) ($counts[IdpApproval::STAGE_PLANNING] ?? 0),
            'result' => (int) ($counts[IdpApproval::STAGE_RESULT] ?? 0),
        ];
    }

    /**
     * Rejected rounds of this owner's requests: still the latest of their
     * subject (`resolved: false`, a task), or answered by a later round
     * (`resolved: true`, history).
     */
    private function revisionQuery(User $user, bool $resolved): ?Builder
    {
        if (blank($user->employee_id)) {
            return null;
        }

        $package = $this->stage->currentPackage();

        // A task is only one the owner can reach: the active cycle's.
        if (! $resolved && ! $package) {
            return null;
        }

        $modelIds = $package ? $this->stage->modelIdsFor($package->id) : [];

        $laterResult = fn ($q) => $q->from('idp_approvals as later')
            ->whereColumn('later.individual_development_plan_id', 'idp_approvals.individual_development_plan_id')
            ->whereColumn('later.id', '>', 'idp_approvals.id');

        $laterPlanning = fn ($q) => $q->from('idp_approvals as later')
            ->where('later.stage', IdpApproval::STAGE_PLANNING)
            ->whereColumn('later.employee_id', 'idp_approvals.employee_id')
            ->whereColumn('later.development_model_package_id', 'idp_approvals.development_model_package_id')
            ->whereColumn('later.id', '>', 'idp_approvals.id');

        return IdpApproval::query()
            ->where('idp_approvals.employee_id', $user->employee_id)
            ->where('idp_approvals.status', 'rejected')
            ->where(function (Builder $query) use ($resolved, $package, $modelIds, $laterResult, $laterPlanning) {
                $query->where(function (Builder $result) use ($resolved, $modelIds, $laterResult) {
                    $result->where('idp_approvals.stage', IdpApproval::STAGE_RESULT);

                    if ($resolved) {
                        $result->whereExists($laterResult);
                    } else {
                        $result->whereNotExists($laterResult)
                            ->whereHas('plan', fn (Builder $q) => $q->whereIn('development_model_id', $modelIds));
                    }
                })->orWhere(function (Builder $planning) use ($resolved, $package, $laterPlanning) {
                    $planning->where('idp_approvals.stage', IdpApproval::STAGE_PLANNING);

                    if ($resolved) {
                        $planning->whereExists($laterPlanning);
                    } else {
                        $planning->whereNotExists($laterPlanning)
                            ->where('idp_approvals.development_model_package_id', $package->id);
                    }
                });
            });
    }

    /**
     * The approval steps this user has already decided — their approval
     * history, newest decision first.
     *
     * Keyed on who ACTED rather than on whose layer it is: the two differ when
     * a chain auto-approves on submission, where one person's submission signs
     * off their own layer and every layer below it. What they caused belongs in
     * their history; a layer somebody else signed off on their behalf does not.
     * Rows predating `acted_by` fall back to the layer's approver, the best
     * attribution they carry.
     *
     * @return Collection<int, IdpApprovalStep>
     */
    public function decidedBy(User $user): Collection
    {
        return $this->decidedQuery($user)
            ->with(['approval.plan', 'approval.package', 'approval.steps'])
            ->orderByDesc('acted_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (IdpApprovalStep $step) => (bool) $step->approval)
            ->values();
    }

    /**
     * How many decisions this user has recorded — the same set decidedBy()
     * returns, counted without loading it.
     */
    public function decidedCountFor(User $user): int
    {
        return $this->decidedQuery($user)->count();
    }

    private function decidedQuery(User $user): Builder
    {
        return IdpApprovalStep::query()
            ->whereIn('status', ['approved', 'rejected'])
            ->whereHas('approval')
            ->where(function (Builder $query) use ($user) {
                $query->where('acted_by', $user->id);

                if (filled($user->employee_id)) {
                    $query->orWhere(fn (Builder $legacy) => $legacy
                        ->whereNull('acted_by')
                        ->where('approver_employee_id', $user->employee_id));
                }
            });
    }

    /**
     * Whether the given user is the approver whose turn it currently is.
     */
    public function isCurrentApprover(IdpApproval $approval, ?string $employeeId): bool
    {
        if (blank($employeeId) || $approval->status !== 'pending') {
            return false;
        }

        return $approval->currentStep()?->approver_employee_id === $employeeId;
    }

    /**
     * Re-read the approval under a row lock, with fresh steps. Two decisions on
     * one request - two approvers, or one double click - then run one after the
     * other, and the second meets the state the first left (no longer pending,
     * or another layer's turn) instead of advancing the chain twice.
     */
    private function lockForDecision(IdpApproval $approval): IdpApproval
    {
        return IdpApproval::whereKey($approval->getKey())
            ->lockForUpdate()
            ->firstOrFail()
            ->load('steps');
    }

    /**
     * @throws ValidationException
     */
    private function guardCurrentApprover(IdpApproval $approval, User $user): IdpApprovalStep
    {
        if ($approval->status !== 'pending') {
            throw ValidationException::withMessages([
                'approval' => 'This request is no longer awaiting approval.',
            ]);
        }

        $step = $approval->currentStep();

        if (! $step || $step->approver_employee_id !== $user->employee_id) {
            throw ValidationException::withMessages([
                'approval' => 'You are not the approver for the current layer of this request.',
            ]);
        }

        return $step;
    }

    // ----------------------------------------------------------- notifications

    /**
     * Send a "need approval" alert to the approver of the given layer — in-app
     * (if they have a user account) and by email (to their account email, or the
     * corporate email as a fallback).
     */
    private function notifyApprover(IdpApproval $approval, int $level): void
    {
        $step = $approval->steps->firstWhere('level', $level);

        if (! $step) {
            return;
        }

        $ownerName = $this->employeeName($approval->employee_id);
        $approverId = $step->approver_employee_id;

        if ($approval->isPlanning()) {
            $count = $this->stage->plansIn($approval->employee_id, (int) $approval->development_model_package_id)->count();
            $title = 'IDP planning approval needed';
            $message = "{$ownerName} needs your approval (Layer {$level}) for their development plan — {$count} program(s).";
        } else {
            $program = $approval->plan?->development_program;
            $title = 'IDP result approval needed';
            $message = "{$ownerName} needs your approval (Layer {$level}) for the result of a development program"
                .($program ? ": {$program}." : '.');
        }

        $user = User::where('employee_id', $approverId)->first();

        if ($user) {
            ApprovalNotification::create([
                'user_id' => $user->id,
                'employee_id' => $approverId,
                'type' => 'approval_requested',
                'idp_approval_id' => $approval->id,
                'individual_development_plan_id' => $approval->individual_development_plan_id,
                'subject_employee_id' => $approval->employee_id,
                'subject_name' => $ownerName,
                'title' => $title,
                'message' => $message,
                'link' => '/approvals',
                'level' => $level,
            ]);
        }

        $this->mailTo(
            $this->resolveEmail($approverId, $user),
            $this->employeeName($approverId),
            $title,
            $message,
            $this->absoluteUrl('/approvals'),
            'Open approvals',
        );
    }

    /**
     * Send the final outcome back to the submitter and the IDP owner, over both
     * channels.
     */
    private function notifyOutcome(IdpApproval $approval, string $type): void
    {
        $ownerName = $this->employeeName($approval->employee_id);
        $approved = $type === 'approval_approved';
        $decided = $approved ? 'approved' : 'rejected';

        if ($approval->isPlanning()) {
            $title = $approved ? 'IDP planning approved' : 'IDP planning rejected';
            $message = "The development plan for {$ownerName} was {$decided}."
                .($approved ? ' Results can now be submitted for its programs.' : ' Revise it and submit it again.');
        } else {
            $program = $approval->plan?->development_program;
            $title = $approved ? 'IDP result approved' : 'IDP result rejected';
            $message = "The result for {$ownerName}"
                .($program ? " on \"{$program}\"" : '')
                ." was {$decided}."
                .($approved ? '' : ' Revise the result and submit it again — it is in your Task Box under Result approvals.');
        }

        // A rejection lands on what is corrected — the program's own row, or
        // the plan's sign-off card — marked until it is resubmitted.
        $focus = match (true) {
            $approved => null,
            $approval->isPlanning() => 'plan',
            default => $approval->individual_development_plan_id,
        };
        $link = '/idp/'.$approval->employee_id.($focus ? '?focus='.$focus : '');
        $url = $this->absoluteUrl($link);

        // Recipients: whoever submitted it, plus the IDP owner. Keyed by user_id
        // for the in-app channel and by email for the mail channel (deduped).
        $submitter = $approval->submitted_by ? User::find($approval->submitted_by) : null;
        $ownerUser = User::where('employee_id', $approval->employee_id)->first();

        $emails = collect();

        foreach ([$submitter, $ownerUser] as $recipient) {
            if (! $recipient) {
                continue;
            }

            ApprovalNotification::create([
                'user_id' => $recipient->id,
                'employee_id' => $recipient->employee_id,
                'type' => $type,
                'idp_approval_id' => $approval->id,
                'individual_development_plan_id' => $approval->individual_development_plan_id,
                'subject_employee_id' => $approval->employee_id,
                'subject_name' => $ownerName,
                'title' => $title,
                'message' => $message,
                'link' => $link,
            ]);

            if (filled($recipient->email)) {
                $emails->put($recipient->email, $recipient->name ?: $ownerName);
            }
        }

        // Fall back to the owner's corporate email if they have no user account.
        if (! $ownerUser) {
            $ownerEmail = $this->resolveEmail($approval->employee_id);
            if ($ownerEmail) {
                $emails->put($ownerEmail, $ownerName);
            }
        }

        foreach ($emails as $email => $name) {
            $this->mailTo($email, $name, $title, $message, $url, 'Open IDP');
        }
    }

    /**
     * Queue a workflow email. Guarded so a mail failure never breaks the
     * approval action; skipped when no address is known.
     */
    private function mailTo(?string $email, string $name, string $subject, string $body, string $url, string $actionText): void
    {
        if (blank($email)) {
            return;
        }

        try {
            Mail::to($email)->send(new ApprovalMail($subject, $name, $body, $url, $actionText));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The best email for an employee: their app account email, else the
     * corporate (kpncorp) email. Null when neither is available.
     */
    private function resolveEmail(string $employeeId, ?User $user = null): ?string
    {
        $user ??= User::where('employee_id', $employeeId)->first();

        if ($user && filled($user->email)) {
            return $user->email;
        }

        try {
            $email = Employee::where('employee_id', $employeeId)->value('email');
        } catch (\Throwable) {
            $email = null;
        }

        return $email ?: null;
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function employeeName(string $employeeId): string
    {
        try {
            $name = Employee::where('employee_id', $employeeId)->value('fullname');
        } catch (\Throwable) {
            $name = null;
        }

        return $name ?: $employeeId;
    }
}
