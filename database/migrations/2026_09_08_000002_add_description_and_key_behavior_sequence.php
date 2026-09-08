<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions to a competency's own proficiency ladder:
 *
 *  - a rung carries a bilingual description, like every other master that has
 *    a name worth explaining;
 *  - a key behavior carries a sequence, so the behaviors under one rung have
 *    an order of their own rather than falling back on insertion order.
 *
 * Both lists are now ordered by row position on the form (the number is
 * assigned server-side from the submitted order and reordered with up/down
 * buttons), which is why the behavior's column needs no unique index — the
 * rungs' `sequence` has never had one either, for the same reason a swap
 * writes the two rows one at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competency_proficiency_levels', function (Blueprint $table) {
            $table->text('description_en')->nullable()->after('name_id');
            $table->text('description_id')->nullable()->after('description_en');
        });

        Schema::table('competency_key_behaviors', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->default(1)->after('name_id');
        });

        // Existing behaviors keep the order they were created in.
        $counters = [];
        foreach (DB::table('competency_key_behaviors')->orderBy('id')->get() as $behavior) {
            $level = $behavior->competency_proficiency_level_id;
            $counters[$level] = ($counters[$level] ?? 0) + 1;

            DB::table('competency_key_behaviors')
                ->where('id', $behavior->id)
                ->update(['sequence' => $counters[$level]]);
        }
    }

    public function down(): void
    {
        Schema::table('competency_proficiency_levels', function (Blueprint $table) {
            $table->dropColumn(['description_en', 'description_id']);
        });

        Schema::table('competency_key_behaviors', function (Blueprint $table) {
            $table->dropColumn('sequence');
        });
    }
};
