<?php

namespace Tests\Unit;

use App\Models\IdpApproval;
use App\Models\IndividualDevelopmentPlan;
use App\Services\Idp\IdpStageService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The headline state of an employee's plan set. The per-row sign-off stamp is
 * the ground truth, so the headline can never contradict what the rows allow.
 */
class PlanningStatusTest extends TestCase
{
    private IdpStageService $stage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stage = $this->app->make(IdpStageService::class);
    }

    /** @param  list<bool>  $approved  one entry per plan: stamped or not */
    private function plans(array $approved): Collection
    {
        return collect($approved)->map(fn (bool $stamped) => (new IndividualDevelopmentPlan)
            ->forceFill(['planning_approved_at' => $stamped ? now() : null]));
    }

    private function approval(?string $status): ?IdpApproval
    {
        return $status === null ? null : (new IdpApproval)->forceFill(['status' => $status]);
    }

    public function test_a_set_never_submitted_is_a_draft(): void
    {
        $this->assertSame(IdpStageService::DRAFT, $this->stage->planningStatus(null, $this->plans([false, false])));
        $this->assertSame(IdpStageService::DRAFT, $this->stage->planningStatus(null, collect()));
    }

    public function test_a_pending_round_wins_over_everything(): void
    {
        $this->assertSame(IdpStageService::PENDING, $this->stage->planningStatus($this->approval('pending'), $this->plans([true, true])));
    }

    public function test_approved_when_every_row_carries_the_stamp(): void
    {
        $this->assertSame(IdpStageService::APPROVED, $this->stage->planningStatus($this->approval('approved'), $this->plans([true, true])));
    }

    public function test_an_approved_set_that_changed_needs_approval_again(): void
    {
        // Approved earlier, then a program was added (it carries no stamp).
        $this->assertSame(IdpStageService::REVISION, $this->stage->planningStatus($this->approval('approved'), $this->plans([true, false])));
    }

    public function test_rejected_unless_every_remaining_row_is_still_signed_off(): void
    {
        $this->assertSame(IdpStageService::REJECTED, $this->stage->planningStatus($this->approval('rejected'), $this->plans([true, false])));

        // Rejected over a program that has since been dropped: what is left is
        // all signed off, so the set reads approved.
        $this->assertSame(IdpStageService::APPROVED, $this->stage->planningStatus($this->approval('rejected'), $this->plans([true])));
    }

    public function test_a_set_is_frozen_only_while_a_round_is_pending(): void
    {
        $this->assertFalse($this->stage->plansEditable($this->approval('pending')));

        foreach ([null, 'approved', 'rejected'] as $status) {
            $this->assertTrue($this->stage->plansEditable($this->approval($status)));
        }
    }
}
