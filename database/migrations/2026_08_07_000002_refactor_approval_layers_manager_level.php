<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move approval layers from the fixed manager_l1 / manager_l2 approver types to
 * a single dynamic "manager_level" type carrying a numeric level, so a flow can
 * have more than two dynamic layers (level 3+ climbs the reporting line).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_layers', function (Blueprint $table) {
            $table->unsignedTinyInteger('manager_level')->nullable()->after('approver_type');
        });

        // Backfill existing dynamic layers into the new model.
        DB::table('approval_layers')->where('approver_type', 'manager_l1')
            ->update(['approver_type' => 'manager_level', 'manager_level' => 1]);
        DB::table('approval_layers')->where('approver_type', 'manager_l2')
            ->update(['approver_type' => 'manager_level', 'manager_level' => 2]);
    }

    public function down(): void
    {
        // Best-effort reverse: levels 1/2 map back to the old fixed types,
        // anything deeper collapses onto manager_l2.
        DB::table('approval_layers')->where('approver_type', 'manager_level')
            ->where('manager_level', 1)
            ->update(['approver_type' => 'manager_l1']);
        DB::table('approval_layers')->where('approver_type', 'manager_level')
            ->update(['approver_type' => 'manager_l2']);

        Schema::table('approval_layers', function (Blueprint $table) {
            $table->dropColumn('manager_level');
        });
    }
};
