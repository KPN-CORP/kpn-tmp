<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Master Implementation" rows (type='implementation') map a single
     * competency + its proficiency level to a corporate org scope. Two of the
     * scope pieces (competency type, proficiency level, business unit, grade)
     * already have columns; these add the rest:
     *
     *  - competency_name_id — the competency being implemented (self-referencing
     *    to a competency_name row).
     *
     * The org scope is a dynamic hierarchy sourced from kpncorp, stored as raw
     * strings (business_unit already exists):
     *  - job_family    — an employee `company_name`, scoped to the business unit.
     *  - function_name — a `departments.department_name`, scoped to the BU.
     *  - position      — a `designations.designation_name`, scoped to BU + function.
     */
    public function up(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->foreignId('competency_name_id')
                ->nullable()
                ->after('competency_type_id')
                ->constrained('development_plan_masters')
                ->nullOnDelete();

            $table->string('job_family')->nullable()->after('grade');
            $table->string('function_name')->nullable()->after('job_family');
            $table->string('position')->nullable()->after('function_name');
        });
    }

    public function down(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->dropForeign(['competency_name_id']);
            $table->dropColumn(['competency_name_id', 'job_family', 'function_name', 'position']);
        });
    }
};
