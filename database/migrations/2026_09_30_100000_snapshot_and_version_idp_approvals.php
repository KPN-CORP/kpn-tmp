<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes that let the approval log show a request as it was decided.
 *
 *  - `snapshot` (json, nullable) — the plans a request covered, frozen at the
 *    moment it was submitted. The log used to read the live plan rows, so every
 *    past round showed today's data. Rows submitted before this column existed
 *    stay null and still read live; nothing recorded what they looked like.
 *  - Result approvals become VERSIONED, like planning ones: a resubmitted result
 *    opens a new row instead of overwriting the old one and deleting its steps.
 *    So the unique index on `individual_development_plan_id` becomes a plain one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idp_approvals', function (Blueprint $table) {
            $table->json('snapshot')->nullable()->after('layers');
        });

        Schema::table('idp_approvals', function (Blueprint $table) {
            // The FK needs an index on the column at all times, so the plain one
            // goes in before the unique one comes out.
            $table->index('individual_development_plan_id', 'idp_approvals_plan_index');
            $table->dropUnique('idp_approvals_individual_development_plan_id_unique');
        });
    }

    public function down(): void
    {
        // Put back one result approval per plan: the latest round wins, which is
        // what every lookup already treated as the current one.
        $keep = DB::table('idp_approvals')
            ->whereNotNull('individual_development_plan_id')
            ->groupBy('individual_development_plan_id')
            ->pluck(DB::raw('MAX(id)'));

        DB::table('idp_approvals')
            ->whereNotNull('individual_development_plan_id')
            ->whereNotIn('id', $keep)
            ->delete();

        Schema::table('idp_approvals', function (Blueprint $table) {
            $table->unique('individual_development_plan_id');
            $table->dropIndex('idp_approvals_plan_index');
            $table->dropColumn('snapshot');
        });
    }
};
