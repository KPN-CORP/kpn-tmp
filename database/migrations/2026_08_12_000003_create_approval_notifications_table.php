<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications for the approval workflow — a "need approval" alert sent
 * to the current layer's approver, and approved / rejected outcomes sent back to
 * the submitter and the IDP owner. Keyed by the recipient user_id (no cross-DB
 * FK: the users table lives on a separate connection). App-owned (mysql).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // The recipient approver's own employee_id, for reference.
            $table->string('employee_id', 25)->nullable();
            // approval_requested | approval_approved | approval_rejected
            $table->string('type', 40);
            $table->unsignedBigInteger('idp_approval_id')->nullable();
            $table->unsignedBigInteger('individual_development_plan_id')->nullable();
            // The IDP owner the request is about.
            $table->string('subject_employee_id', 25)->nullable();
            $table->string('subject_name')->nullable();
            $table->string('title');
            $table->text('message')->nullable();
            // Where clicking the notification should land.
            $table->string('link')->nullable();
            $table->unsignedTinyInteger('level')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_notifications');
    }
};
