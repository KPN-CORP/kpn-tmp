<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approval workflow header for a single IDP item (one competency /
 * development program row). Approval is staged: L1 acts first, then L2, and so
 * on down the employee's approval chain. The chain is snapshotted into `layers`
 * at submission time so later edits to the approval-setting page never shift an
 * in-flight request. App-owned (default mysql, same DB as the IDP item).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idp_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('individual_development_plan_id');
            // The IDP owner (kpncorp employee_id), denormalized for querying.
            $table->string('employee_id', 25);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            // 1-based index into `layers` of the layer whose turn it currently is.
            $table->unsignedTinyInteger('current_level')->default(1);
            // Ordered snapshot of approver employee_ids at submission time.
            $table->json('layers');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique('individual_development_plan_id');
            $table->index('employee_id');
            $table->index('status');

            $table->foreign('individual_development_plan_id')
                ->references('id')->on('individual_development_plans')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idp_approvals');
    }
};
