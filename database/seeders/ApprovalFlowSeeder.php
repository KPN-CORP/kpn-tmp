<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\ApprovalLayer;
use Illuminate\Database\Seeder;

/**
 * Seeds the default approval flow for each module, reproducing facecard's
 * behavior: a two-layer chain of Direct Superior (manager_l1) then Second-Level
 * Manager (manager_l2). Idempotent — admins can then add/reorder layers or swap
 * approvers from the Approval Setting screen.
 */
class ApprovalFlowSeeder extends Seeder
{
    public function run(): void
    {
        $flows = [
            'idp' => 'Development Plan Approval',
            'appraisal' => 'Performance Appraisal Approval',
        ];

        foreach ($flows as $module => $name) {
            $flow = ApprovalFlow::updateOrCreate(
                ['module' => $module],
                ['name' => $name, 'is_active' => true],
            );

            // Only seed the default layers when the flow has none yet, so a
            // re-seed never clobbers an admin's customized chain.
            if ($flow->layers()->exists()) {
                continue;
            }

            $defaults = [
                ['sequence' => 1, 'name' => 'Direct Superior', 'approver_type' => ApprovalLayer::TYPE_MANAGER_L1],
                ['sequence' => 2, 'name' => 'Second-Level Manager', 'approver_type' => ApprovalLayer::TYPE_MANAGER_L2],
            ];

            foreach ($defaults as $layer) {
                $flow->layers()->create($layer + ['is_active' => true]);
            }
        }
    }
}
