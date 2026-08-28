<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A development_program row may now be scoped to *several* grades instead of
     * one, so this json array replaces the singular `grade` pin for programs.
     * The singular column stays (master implementation rows still use it) and is
     * kept in step with the first element for anything that reads one value.
     * The program's `business_unit` scope was dropped from the screen; the
     * column stays because implementation rows use it too.
     */
    public function up(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->json('grades')->nullable()->after('grade');
        });

        // Backfill the array from the existing singular pin on program rows.
        DB::table('development_plan_masters')
            ->where('type', 'development_program')
            ->whereNotNull('grade')
            ->where('grade', '<>', '')
            ->select('id', 'grade')
            ->orderBy('id')
            ->get()
            ->each(function ($row) {
                DB::table('development_plan_masters')->where('id', $row->id)->update([
                    'grades' => json_encode([$row->grade]),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->dropColumn('grades');
        });
    }
};
