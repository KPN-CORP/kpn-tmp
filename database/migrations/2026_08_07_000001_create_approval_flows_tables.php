<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable approval workflow.
 *
 * facecard hard-coded the approver as the employee's manager_l1 / manager_l2.
 * Here the flow is data: one `approval_flows` row per module (idp, appraisal)
 * owning an ordered list of `approval_layers`. Each layer resolves its approver
 * either dynamically from the employee record (manager_l1 / manager_l2) or from
 * a specifically chosen employee, so admins can add layers and swap approvers
 * without a code change.
 *
 * Both tables are app-owned (default `mysql` connection). `approver_employee_id`
 * points at a kpncorp employee but is intentionally NOT a foreign key — the two
 * live on different connections and can't be constrained across databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_flows', function (Blueprint $table) {
            $table->id();
            // 'idp' | 'appraisal' — one flow per module (global, single flow).
            $table->string('module', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('approval_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_flow_id')
                ->constrained('approval_flows')
                ->cascadeOnDelete();
            // 1-based order of the layer within its flow.
            $table->unsignedTinyInteger('sequence')->default(1);
            // Human label for the step, e.g. "Direct Superior", "HR Head".
            $table->string('name');
            // 'manager_l1' | 'manager_l2' | 'specific_employee'
            $table->string('approver_type', 30)->default('manager_l1');
            // Set only when approver_type = specific_employee (kpncorp employee_id).
            $table->string('approver_employee_id', 25)->nullable();
            // Lets a layer be switched off without deleting/resequencing it.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Ordering is enforced app-side (reorder/swap would clash a unique),
            // so index rather than constrain the (flow, sequence) pair.
            $table->index(['approval_flow_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_layers');
        Schema::dropIfExists('approval_flows');
    }
};
