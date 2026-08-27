<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A competency (competency_name row) may now pin *several* proficiency levels
     * and, under them, *several* key behaviors — replacing the single
     * proficiency_level_id / key_behavior_id pins. These json arrays hold the full
     * multi-selection; the legacy singular columns are kept in step (first element)
     * so the screens that still read one value (development-program + master
     * implementation forms) keep working unchanged.
     */
    public function up(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->json('proficiency_level_ids')->nullable()->after('proficiency_level_id');
            $table->json('key_behavior_ids')->nullable()->after('key_behavior_id');
        });

        // Backfill the arrays from the existing singular pins on competency rows.
        DB::table('development_plan_masters')
            ->where('type', 'competency_name')
            ->whereNotNull('proficiency_level_id')
            ->select('id', 'proficiency_level_id', 'key_behavior_id')
            ->orderBy('id')
            ->get()
            ->each(function ($row) {
                DB::table('development_plan_masters')->where('id', $row->id)->update([
                    'proficiency_level_ids' => json_encode([(int) $row->proficiency_level_id]),
                    'key_behavior_ids' => $row->key_behavior_id
                        ? json_encode([(int) $row->key_behavior_id])
                        : null,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->dropColumn(['proficiency_level_ids', 'key_behavior_ids']);
        });
    }
};
