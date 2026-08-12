<?php

namespace App\Services;

use App\Mail\ApprovalMail;
use App\Models\ApprovalNotification;
use App\Models\Employee;
use App\Models\IdpApproval;
use App\Models\IdpApprovalStep;
use App\Models\IndividualDevelopmentPlan;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The approval runtime for IDP items. Submitting an item snapshots the
 * employee's approval chain and opens a staged workflow (L1 → L2 → …). Only the
 * approver whose turn it currently is may act; each decision carries a required
 * note. Every transition raises an in-app notification: a "need approval" alert
 * to the next approver, and the approved / rejected outcome back to the
 * submitter and the IDP owner.
 */
class IdpApprovalService
{
    public function __construct(private readonly ApprovalChainService $chain) {}

    /**
     * Open (or re-open) the approval workflow for an IDP item. A rejected item
     * can be resubmitted, which starts a fresh chain from L1.
     *
     * @throws ValidationException when the workflow cannot be started.
     */
    public function submit(IndividualDevelopmentPlan $plan, User $user): IdpApproval
    {
        $existing = IdpApproval::where('individual_development_plan_id', $plan->id)->first();

        if ($existing && $existing->status === 'pending') {
            throw ValidationException::withMessages([
                'approval' => 'This item is already awaiting approval.',
            ]);
        }

        if ($existing && $existing->status === 'approved') {
            throw ValidationException::withMessages([
                'approval' => 'This item has already been approved.',
            ]);
        }

        // An item can only be submitted once it is completed (realized).
        if (blank($plan->realization_date)) {
            throw ValidationException::withMessages([
                'approval' => 'This item must be completed (fill the realization date) before it can be submitted for approval.',
            ]);
        }

        $layers = $this->chain->layersFor($plan->employee_id);

        if (empty($layers)) {
            throw ValidationException::withMessages([
                'approval' => 'No approval superiors are configured for this employee. Set them on the Approval Layer screen first.',
            ]);
        }

        return DB::transaction(function () use ($plan, $user, $layers) {
            $approval = IdpApproval::updateOrCreate(
                ['individual_development_plan_id' => $plan->id],
                [
                    'employee_id' => $plan->employee_id,
                    'status' => 'pending',
                    'current_level' => 1,
                    'layers' => $layers,
                    'submitted_by' => $user->id,
                    'submitted_at' => now(),
                ],
            );

            // Start each chain fresh.
            $approval->steps()->delete();

            foreach ($layers as $index => $approverId) {
                IdpApprovalStep::create([
                    'idp_approval_id' => $approval->id,
                    'level' => $index + 1,
                    'approver_employee_id' => $approverId,
                    'status' => 'pending',
                ]);
            }

            $approval->load('steps');

            // Notify the first approver that their action is needed.
            $this->notifyApprover($approval, 1);

            return $approval;
        });
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
                $this->notifyOutcome($approval, 'approval_approved');
            }

            return $approval->refresh();
        });
    }

    /**
     * Record the current approver's rejection. The chain stops; the owner can
     * revise the item and resubmit from L1.
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
            ->with('approval.plan')
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
                'approval' => 'This item is no longer awaiting approval.',
            ]);
        }

        $step = $approval->currentStep();

        if (! $step || $step->approver_employee_id !== $user->employee_id) {
            throw ValidationException::withMessages([
                'approval' => 'You are not the approver for the current layer of this item.',
            ]);
        }

        return $step;
    }

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
        $title = 'IDP approval needed';
        $message = "{$ownerName} needs your approval (Layer {$level}) for a development plan item.";

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
        $decided = $type === 'approval_approved' ? 'approved' : 'rejected';
        $title = $type === 'approval_approved' ? 'IDP item approved' : 'IDP item rejected';
        $message = "The development plan item for {$ownerName} was {$decided}.";
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
