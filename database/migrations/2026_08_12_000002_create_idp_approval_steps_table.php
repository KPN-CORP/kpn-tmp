<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per layer of an IDP item's approval chain (L1, L2, …). Each carries
 * the acting approver's decision and the required note. Steps are recreated
 * whenever an item is (re)submitted, so a rejected item can start a fresh chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idp_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('idp_approval_id')->constrained('idp_approvals')->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('approver_employee_id', 25);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('acted_by')->nullable();
            $table->string('acted_by_name')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->index(['approver_employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idp_approval_steps');
    }
};
