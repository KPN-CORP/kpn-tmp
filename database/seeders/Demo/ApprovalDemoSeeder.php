<?php

namespace Database\Seeders\Demo;

use App\Models\ApprovalNotification;
use App\Models\ApprovalSuperior;
use App\Models\ApprovalSuperiorHistory;
use App\Models\Employee;
use App\Models\IdpApproval;
use App\Models\IndividualDevelopmentPlan;
use App\Models\User;
use App\Services\ApprovalChainService;
use App\Services\IdpApprovalService;
use Illuminate\Database\Seeder;

/**
 * Demo approval data: a few explicit approval-layer overrides (the rest of the
 * population keeps defaulting to the corporate manager_l1 / manager_l2), plus a
 * spread of IDP items pushed through the real approval runtime so the inbox,
 * the per-item chain drawer and the notification bell all have something in
 * them.
 *
 * The workflows are driven through {@see IdpApprovalService} rather than by
 * writing the rows directly, so the seeded state is exactly what the app itself
 * would produce -- including the notifications each transition raises.
 */
class ApprovalDemoSeeder extends Seeder
{
    /**
     * How many IDP items should be in the approval workflow in total. A re-run
     * tops the number up rather than submitting this many again, so the data
     * settles instead of growing every time the seeder is called.
     */
    private const ITEMS_IN_WORKFLOW = 90;

    /** How many employees get an explicit (longer) chain instead of the default. */
    private const CHAIN_OVERRIDES = 8;

    private const APPROVE_NOTES = [
        'Agreed -- the evidence matches what we discussed in the review.',
        'Approved. Good progress on this competency over the period.',
        'Signed off; please carry the same approach into next cycle.',
    ];

    private const REJECT_NOTES = [
        'Please attach the completion certificate before resubmitting.',
        'The expected outcome is not evidenced yet -- revise and resubmit.',
    ];

    public function __construct(
        private readonly ApprovalChainService $chain,
        private readonly IdpApprovalService $approvals,
    ) {}

    public function run(): void
    {
        $this->seedChainOverrides();
        $this->seedWorkflows();

        $this->command?->info('  chain overrides: '.ApprovalSuperior::count()
            .' | approvals: '.IdpApproval::count()
            .' (pending '.IdpApproval::where('status', 'pending')->count()
            .', approved '.IdpApproval::where('status', 'approved')->count()
            .', rejected '.IdpApproval::where('status', 'rejected')->count().')'
            .' | notifications: '.ApprovalNotification::count());
    }

    /**
     * Explicit chains for a few employees: their two corporate managers plus
     * their manager's own manager as a third layer, which is the case the
     * dynamic layer list exists for.
     */
    private function seedChainOverrides(): void
    {
        try {
            $employees = Employee::query()
                ->whereNotNull('manager_l1_id')->where('manager_l1_id', '<>', '')
                ->whereNotNull('manager_l2_id')->where('manager_l2_id', '<>', '')
                ->orderBy('employee_id')
                ->limit(self::CHAIN_OVERRIDES)
                ->get(['employee_id', 'fullname', 'manager_l1_id', 'manager_l2_id']);
        } catch (\Throwable) {
            $this->command?->warn('  kpncorp unreachable -- approval chains skipped.');

            return;
        }

        $actor = $this->actor();

        foreach ($employees as $employee) {
            $layers = [$employee->manager_l1_id, $employee->manager_l2_id];

            // A third layer, where the corporate tree offers one.
            $grandparent = Employee::where('employee_id', $employee->manager_l2_id)
                ->value('manager_l1_id');

            if (filled($grandparent) && ! in_array($grandparent, $layers, true)) {
                $layers[] = $grandparent;
            }

            $override = ApprovalSuperior::updateOrCreate(
                ['employee_id' => $employee->employee_id],
                ['layers' => array_values($layers), 'updated_by' => $actor?->id],
            );

            if ($override->wasRecentlyCreated) {
                ApprovalSuperiorHistory::create([
                    'employee_id' => $employee->employee_id,
                    'layers' => array_values($layers),
                    'changed_by' => $actor?->id,
                    'changed_by_name' => $actor?->name ?? 'Demo data',
                ]);
            }
        }
    }

    /**
     * Push completed IDP items through submit / approve / reject so the data
     * covers every state the inbox can show.
     */
    private function seedWorkflows(): void
    {
        $shortfall = self::ITEMS_IN_WORKFLOW - IdpApproval::count();

        if ($shortfall <= 0) {
            return;
        }

        $plans = IndividualDevelopmentPlan::query()
            ->whereNotNull('realization_date')
            ->whereNotIn('id', IdpApproval::query()->select('individual_development_plan_id'))
            ->orderBy('id')
            ->limit($shortfall)
            ->get();

        if ($plans->isEmpty()) {
            return;
        }

        $submitted = 0;

        foreach ($plans->values() as $i => $plan) {
            $owner = $this->userFor($plan->employee_id);

            if (! $owner) {
                continue;
            }

            try {
                $approval = $this->approvals->submit($plan, $owner);
            } catch (\Throwable) {
                // No chain configured for this employee, or the item is already
                // in flight -- both are ordinary, just move on.
                continue;
            }

            $submitted++;

            // Leave every third workflow sitting at its first layer so the
            // approver inbox is not empty.
            if ($i % 3 === 0) {
                continue;
            }

            $this->act($approval, reject: $i % 7 === 0, index: $i);
        }

        $this->command?->info('  submitted '.$submitted.' IDP items into the approval workflow.');
    }

    /**
     * Act as the current approver: reject outright, or approve layer by layer
     * until the chain either finishes or runs out of approvers with accounts.
     */
    private function act(IdpApproval $approval, bool $reject, int $index): void
    {
        $guard = 0;

        while ($approval->status === 'pending' && $guard++ < 10) {
            $approverId = $approval->layers[$approval->current_level - 1] ?? null;
            $approver = $approverId ? $this->userFor($approverId) : null;

            if (! $approver) {
                return;
            }

            try {
                if ($reject) {
                    $this->approvals->reject($approval, $approver, self::REJECT_NOTES[$index % count(self::REJECT_NOTES)]);

                    return;
                }

                $approval = $this->approvals->approve(
                    $approval,
                    $approver,
                    self::APPROVE_NOTES[$index % count(self::APPROVE_NOTES)],
                );
            } catch (\Throwable) {
                return;
            }

            // Half the multi-layer chains are left mid-flight, awaiting the
            // next layer rather than fully signed off.
            if ($index % 2 === 0) {
                return;
            }
        }
    }

    /** @var array<string, User|null> */
    private array $users = [];

    private function userFor(?string $employeeId): ?User
    {
        if (blank($employeeId)) {
            return null;
        }

        return $this->users[$employeeId] ??= User::where('employee_id', $employeeId)->first();
    }

    /** The account credited with the seeded chain changes. */
    private function actor(): ?User
    {
        return $this->userFor('01126010024')
            ?? User::whereNotNull('employee_id')->orderBy('id')->first();
    }
}
