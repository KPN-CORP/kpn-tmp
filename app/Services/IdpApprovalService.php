<?php

namespace App\Services;

use App\Mail\ApprovalMail;
use App\Models\ApprovalNotification;
use App\Models\Employee;
use App\Models\IdpApproval;
use App\Models\IdpApprovalStep;
use App\Models\IndividualDevelopmentPlan;
use App\Models\User;
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
        $current = $this->stage->planningApproval($employeeId, $packageId);

        if ($current?->status === 'pending') {
            throw ValidationException::withMessages([
                'approval' => 'This development plan is already awaiting planning approval.',
            ]);
        }

        $plans = $this->stage->plansIn($employeeId, $packageId);

        if ($plans->isEmpty()) {
            throw ValidationException::withMessages([
                'approval' => 'Add at least one development plan before submitting the planning for approval.',
            ]);
        }

        // Nothing to approve: every row already carries a sign-off. Keyed on the
        // rows rather than the header, for the same reason planningStatus() is.
        if ($plans->every->isPlanningApproved()) {
            throw ValidationException::withMessages([
                'approval' => 'This development plan has already been approved and nothing has changed since.',
            ]);
        }

        $layers = $this->requireLayers($employeeId);

        return DB::transaction(function () use ($employeeId, $packageId, $user, $layers) {
            $approval = IdpApproval::create([
                'stage' => IdpApproval::STAGE_PLANNING,
                'individual_development_plan_id' => null,
                'development_model_package_id' => $packageId,
                'employee_id' => $employeeId,
                'status' => 'pending',
                'current_level' => 1,
                'layers' => $layers,
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
        $existing = IdpApproval::result()
            ->where('individual_development_plan_id', $plan->id)
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

        $layers = $this->requireLayers($plan->employee_id);

        return DB::transaction(function () use ($plan, $user, $layers) {
            $approval = IdpApproval::updateOrCreate(
                [
                    'individual_development_plan_id' => $plan->id,
                ],
                [
                    'stage' => IdpApproval::STAGE_RESULT,
                    'development_model_package_id' => null,
                    'employee_id' => $plan->employee_id,
                    'status' => 'pending',
                    'current_level' => 1,
                    'layers' => $layers,
                    'submitted_by' => $user->id,
                    'submitted_at' => now(),
                ],
            );

            // Start each chain fresh, so a rejected result resubmits from L1.
            $approval->steps()->delete();

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
     * The approval steps currently awaiting a given user's action (they are the
     * approver for the layer whose turn it is on a still-pending workflow).
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
            ->with(['approval.plan', 'approval.package'])
            ->get()
            ->filter(fn (IdpApprovalStep $step) => $step->approval
                && $step->approval->status === 'pending'
                && $step->approval->current_level === $step->level)
            ->values();
    }

    public function pendingCountFor(User $user): int
    {
        return $this->pendingFor($user)->count();
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
                ." was {$decided}.";
        }

        $link = '/idp/'.$approval->employee_id;
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
