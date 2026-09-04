<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `expected_outcome` is optional everywhere else — the form request validates it
 * `nullable`, the drawer marks the field optional, and the plans table renders
 * it behind a `v-if` — but the column was created NOT NULL with no default. An
 * empty textarea arrives as null (ConvertEmptyStringsToNull), so saving a plan
 * without an outcome raised a 1048 integrity error rather than validating.
 *
 * Aligns the column with the two other optional free-text fields on the table
 * (`review_tools`, `result_evidence`), both already nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('individual_development_plans', function (Blueprint $table) {
            $table->longText('expected_outcome')->nullable()->change();
        });
    }

    public function down(): void
    {
        // NOT NULL cannot hold the nulls this migration allows, so blank them
        // first — an empty outcome and a missing one mean the same thing here.
        DB::table('individual_development_plans')
            ->whereNull('expected_outcome')
            ->update(['expected_outcome' => '']);

        Schema::table('individual_development_plans', function (Blueprint $table) {
            $table->longText('expected_outcome')->nullable(false)->change();
        });
    }
};
