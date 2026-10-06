<?php

namespace Tests\Unit;

use App\Services\Idp\ApprovalSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The Task Box compares a resubmitted request with the round before it. The
 * diff must report real changes only: 3 vs 3.0 or '' vs null is not a change.
 */
class ApprovalSnapshotDiffTest extends TestCase
{
    private function row(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'development_program' => 'Coaching',
            'target' => 3,
            'uom' => 'times',
            'expected_outcome' => 'Leads a session',
        ], $overrides);
    }

    public function test_added_removed_and_changed_programs_are_told_apart(): void
    {
        $before = [$this->row(1), $this->row(2), $this->row(3)];
        $after = [
            $this->row(1),                                         // unchanged
            $this->row(2, ['target' => 5, 'uom' => 'hour']),       // changed
            $this->row(4, ['development_program' => 'Mentoring']), // added
        ];                                                         // 3 removed

        $diff = ApprovalSnapshot::diff($before, $after);

        $this->assertSame([4], $diff['added']);
        $this->assertSame([3], array_column($diff['removed'], 'id'));
        $this->assertSame([2], array_keys($diff['changed']));
        $this->assertSame([3, 5], $diff['changed'][2]['target']);
        $this->assertSame(['times', 'hour'], $diff['changed'][2]['uom']);
    }

    public function test_formatting_differences_are_not_changes(): void
    {
        $diff = ApprovalSnapshot::diff(
            [$this->row(1, ['target' => 3, 'expected_outcome' => ''])],
            [$this->row(1, ['target' => '3.00', 'expected_outcome' => null])],
        );

        $this->assertSame([], $diff['changed']);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
    }

    public function test_surrounding_whitespace_is_not_a_change(): void
    {
        $diff = ApprovalSnapshot::diff(
            [$this->row(1, ['development_program' => 'Coaching'])],
            [$this->row(1, ['development_program' => '  Coaching '])],
        );

        $this->assertSame([], $diff['changed']);
    }
}
