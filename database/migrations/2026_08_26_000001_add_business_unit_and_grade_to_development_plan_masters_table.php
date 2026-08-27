<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A development_program row can now be scoped to a single business unit
     * (kpncorp `master_bisnisunits.nama_bisnis`, matched against an employee's
     * `group_company`) and grade (an employee's `job_level`). Both are stored as
     * the raw string value; null means the program applies regardless.
     */
    public function up(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->string('business_unit')->nullable()->after('proficiency_level_id');
            $table->string('grade')->nullable()->after('business_unit');
        });
    }

    public function down(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->dropColumn(['business_unit', 'grade']);
        });
    }
};
