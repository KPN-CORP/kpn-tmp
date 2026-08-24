<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A development-model package is now scoped to the corporate business units
     * (kpncorp `master_bisnisunits.nama_bisnis`, matched against an employee's
     * `group_company`) and grade levels (an employee's `job_level`) it applies
     * to. Both are ordered JSON arrays of the raw string values. Multiple
     * packages can therefore be active in the same period, each for a different
     * business-unit / grade audience; an employee's active package is the most
     * specific one that matches their business unit AND grade.
     */
    public function up(): void
    {
        Schema::table('development_model_packages', function (Blueprint $table) {
            $table->json('business_units')->nullable()->after('name');
            $table->json('grades')->nullable()->after('business_units');
        });
    }

    public function down(): void
    {
        Schema::table('development_model_packages', function (Blueprint $table) {
            $table->dropColumn(['business_units', 'grades']);
        });
    }
};
