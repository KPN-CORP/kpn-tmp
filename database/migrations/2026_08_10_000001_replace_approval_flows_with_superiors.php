<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot the approval config from per-module flows to a per-employee model.
 *
 * Every employee gets their own ordered superiors (up to 5 layers), each a
 * specific approver employee_id. Layers 1 & 2 default from the corporate
 * manager_l1 / manager_l2; the rest are added here. Changes are audited in
 * `approval_superior_histories`.
 *
 * The old per-module `approval_flows` / `approval_layers` tables are dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('approval_layers');
        Schema::dropIfExists('approval_flows');

        Schema::create('approval_superiors', function (Blueprint $table) {
            $table->id();
            // Subject employee (kpncorp employee_id — not a cross-DB FK).
            $table->string('employee_id', 25)->unique();
            $table->string('layer_1_id', 25)->nullable();
            $table->string('layer_2_id', 25)->nullable();
            $table->string('layer_3_id', 25)->nullable();
            $table->string('layer_4_id', 25)->nullable();
            $table->string('layer_5_id', 25)->nullable();
            // App user who last saved this mapping.
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('approval_superior_histories', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id', 25)->index();
            // Snapshot of the five layer ids as saved, e.g. {"layer_1_id": "…"}.
            $table->json('layers');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('changed_by_name')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_superior_histories');
        Schema::dropIfExists('approval_superiors');

        // Recreate the previous per-module tables so a rollback is coherent.
        Schema::create('approval_flows', function (Blueprint $table) {
            $table->id();
            $table->string('module', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('approval_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_flow_id')->constrained('approval_flows')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence')->default(1);
            $table->string('name');
            $table->string('approver_type', 30)->default('manager_level');
            $table->unsignedTinyInteger('manager_level')->nullable();
            $table->string('approver_employee_id', 25)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['approval_flow_id', 'sequence']);
        });
    }
};
