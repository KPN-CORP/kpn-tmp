<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the IDP workflow into two stages.
 *
 *  - PLANNING: one approval per employee per development-model package,
 *    covering every plan filed under that package's models. It is signed off
 *    once, layer by layer, before any result may be submitted.
 *  - RESULT: one approval per plan row, raised when that program's realization
 *    date + evidence are submitted.
 *
 * Both live in `idp_approvals`, told apart by `stage`: a planning row carries a
 * `development_model_package_id` and no plan, a result row the reverse. Sharing
 * the table keeps one steps table, one notification table, one inbox and one
 * runtime service for both.
 *
 * `individual_development_plans.planning_approved_at` records, per row, that the
 * row was part of an approved planning set — which is what opens its result
 * stage. Editing the row clears it (and sends the set back for re-approval), so
 * the flag is per row rather than read off the header.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('individual_development_plans', function (Blueprint $table) {
            $table->timestamp('planning_approved_at')->nullable()->after('result_evidence');
        });

        Schema::table('idp_approvals', function (Blueprint $table) {
            $table->enum('stage', ['planning', 'result'])->default('result')->after('id');
            $table->unsignedBigInteger('development_model_package_id')->nullable()->after('individual_development_plan_id');
        });

        // A planning approval has no plan of its own, so the column has to
        // accept null. MySQL allows any number of NULLs under a unique index,
        // which keeps "one result approval per plan" intact.
        Schema::table('idp_approvals', function (Blueprint $table) {
            $table->dropForeign(['individual_development_plan_id']);
            $table->dropUnique('idp_approvals_individual_development_plan_id_unique');
        });

        Schema::table('idp_approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('individual_development_plan_id')->nullable()->change();
        });

        Schema::table('idp_approvals', function (Blueprint $table) {
            $table->unique('individual_development_plan_id');
            // Deliberately NOT unique: a planning set that is revised and
            // resubmitted opens a NEW row, so each revision keeps its own chain
            // and decisions. The current planning approval is the latest row for
            // the pair; the earlier ones are its history.
            $table->index(['employee_id', 'development_model_package_id'], 'idp_approvals_employee_package_index');
            $table->index('stage');

            $table->foreign('individual_development_plan_id')
                ->references('id')->on('individual_development_plans')
                ->cascadeOnDelete();
            $table->foreign('development_model_package_id')
                ->references('id')->on('development_model_packages')
                ->cascadeOnDelete();
        });

        $this->carryOverExistingApprovals();
    }

    /**
     * Every approval that exists today was raised under the single-stage
     * workflow, where an item could only be submitted once it was realized —
     * i.e. they are all RESULT approvals (the default the new column takes).
     *
     * Those sets were therefore already working their way through the chain
     * with no planning gate. Leaving them without one would strand approved
     * results behind a planning step that never happened, so each affected
     * (employee, package) gets a planning approval recorded as approved and
     * labelled as carried over.
     */
    private function carryOverExistingApprovals(): void
    {
        $packageOf = DB::table('development_models')
            ->pluck('development_model_package_id', 'id');

        $rows = DB::table('idp_approvals')
            ->join('individual_development_plans as p', 'p.id', '=', 'idp_approvals.individual_development_plan_id')
            ->select([
                'idp_approvals.employee_id',
                'idp_approvals.layers',
                'idp_approvals.submitted_by',
                'idp_approvals.submitted_at',
                'p.development_model_id',
            ])
            ->get();

        $sets = [];

        foreach ($rows as $row) {
            $packageId = $packageOf[$row->development_model_id] ?? null;

            if ($packageId === null) {
                continue;
            }

            $key = $row->employee_id.'|'.$packageId;
            $existing = $sets[$key] ?? null;

            // Keep the earliest submission as the set's own submission date.
            if (! $existing || ($row->submitted_at && $row->submitted_at < $existing['submitted_at'])) {
                $sets[$key] = [
                    'employee_id' => $row->employee_id,
                    'package_id' => $packageId,
                    'layers' => $row->layers,
                    'submitted_by' => $row->submitted_by,
                    'submitted_at' => $row->submitted_at ?? now()->toDateTimeString(),
                ];
            }
        }

        $note = 'Carried over: this plan set was already in the single-stage workflow that preceded staged planning approval.';

        foreach ($sets as $set) {
            $layers = json_decode($set['layers'], true) ?: [];
            $at = $set['submitted_at'];

            $modelIds = $packageOf
                ->filter(fn ($packageId) => $packageId === $set['package_id'])
                ->keys()
                ->all();

            $approvalId = DB::table('idp_approvals')->insertGetId([
                'stage' => 'planning',
                'individual_development_plan_id' => null,
                'development_model_package_id' => $set['package_id'],
                'employee_id' => $set['employee_id'],
                'status' => 'approved',
                'current_level' => max(1, count($layers)),
                'layers' => $set['layers'],
                'submitted_by' => $set['submitted_by'],
                'submitted_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            foreach (array_values($layers) as $index => $approverId) {
                DB::table('idp_approval_steps')->insert([
                    'idp_approval_id' => $approvalId,
                    'level' => $index + 1,
                    'approver_employee_id' => $approverId,
                    'status' => 'approved',
                    'note' => $note,
                    'acted_by' => null,
                    'acted_by_name' => null,
                    'acted_at' => $at,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }

            // The planning approval covers the whole set, so every plan of this
            // employee under that package is stamped — not only the rows that
            // happened to have been submitted.
            DB::table('individual_development_plans')
                ->where('employee_id', $set['employee_id'])
                ->whereIn('development_model_id', $modelIds)
                ->update(['planning_approved_at' => $at]);
        }
    }

    public function down(): void
    {
        // Planning approvals only exist under this migration; their steps go
        // with them through the cascading FK.
        DB::table('idp_approvals')->where('stage', 'planning')->delete();

        // Any result approval left without a plan cannot be restored to a
        // NOT NULL column, and means nothing without its plan anyway.
        DB::table('idp_approvals')->whereNull('individual_development_plan_id')->delete();

        Schema::table('idp_approvals', function (Blueprint $table) {
            $table->dropForeign(['individual_development_plan_id']);
            $table->dropForeign(['development_model_package_id']);
            $table->dropIndex('idp_approvals_employee_package_index');
            $table->dropUnique('idp_approvals_individual_development_plan_id_unique');
            $table->dropIndex(['stage']);
        });

        Schema::table('idp_approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('individual_development_plan_id')->nullable(false)->change();
        });

        Schema::table('idp_approvals', function (Blueprint $table) {
            $table->unique('individual_development_plan_id');
            $table->foreign('individual_development_plan_id')
                ->references('id')->on('individual_development_plans')
                ->cascadeOnDelete();
            $table->dropColumn(['stage', 'development_model_package_id']);
        });

        Schema::table('individual_development_plans', function (Blueprint $table) {
            $table->dropColumn('planning_approved_at');
        });
    }
};
