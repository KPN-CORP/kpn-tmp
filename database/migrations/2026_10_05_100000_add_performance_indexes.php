<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for two queries that scanned their whole table.
 *
 *  - idp_approval_steps(acted_by, status): the Task Box history finds the steps
 *    a user decided by `acted_by` (with a legacy fallback on
 *    approver_employee_id, which the existing (approver_employee_id, status)
 *    index already serves). Without it every history load read every step.
 *
 *  - individual_development_plans(employee_id, time_frame_end) REPLACES the
 *    single-column employee_id index: the leftmost column still serves every
 *    "plans of this employee" lookup, and the report's per-year plan load
 *    (employee_ids + a time_frame_end range) is answered from the index too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idp_approval_steps', function (Blueprint $table) {
            $table->index(['acted_by', 'status'], 'idp_approval_steps_acted_by_status_index');
        });

        Schema::table('individual_development_plans', function (Blueprint $table) {
            $table->index(['employee_id', 'time_frame_end'], 'idp_plans_employee_time_frame_end_index');
            $table->dropIndex('individual_development_plans_employee_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('individual_development_plans', function (Blueprint $table) {
            $table->index('employee_id', 'individual_development_plans_employee_id_index');
            $table->dropIndex('idp_plans_employee_time_frame_end_index');
        });

        Schema::table('idp_approval_steps', function (Blueprint $table) {
            $table->dropIndex('idp_approval_steps_acted_by_status_index');
        });
    }
};
