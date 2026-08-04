<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the corporate employee master (kpncorp) with a ~286-row sample extracted
 * from the legacy dump, plus a `performance_appraisals` table (also corporate)
 * so the year-on-year 9-box feature has somewhere to write.
 *
 * Local/dev only — it drops & recreates the `employees` table from the dump's
 * real schema. Skips gracefully if the kpncorp connection is unavailable.
 */
class EmployeeSampleSeeder extends Seeder
{
    public function run(): void
    {
        try {
            DB::connection('kpncorp')->getPdo();
        } catch (\Throwable $e) {
            $this->command?->warn('kpncorp connection unavailable — skipping employee sample.');

            return;
        }

        DB::connection('kpncorp')->statement('SET FOREIGN_KEY_CHECKS=0;');

        // employees: schema (DROP+CREATE) then data, straight from the dump.
        DB::connection('kpncorp')->unprepared(file_get_contents(database_path('seeders/schema/employees.sql')));
        DB::connection('kpncorp')->unprepared(file_get_contents(database_path('seeders/data/employees.sql')));

        // performance_appraisals is corporate data too (grade comes from HR; the
        // app writes potential/talent_box). No migration owns it, so create it here.
        Schema::connection('kpncorp')->dropIfExists('performance_appraisals');
        Schema::connection('kpncorp')->create('performance_appraisals', function ($table) {
            $table->id();
            $table->string('employee_id', 25)->index();
            $table->year('appraisal_year');
            $table->string('grade', 10)->nullable();
            $table->string('potential', 20)->nullable();
            $table->string('talent_box', 50)->nullable();
            $table->timestamps();
        });

        DB::connection('kpncorp')->statement('SET FOREIGN_KEY_CHECKS=1;');

        $count = DB::connection('kpncorp')->table('employees')->count();
        $this->command?->info("Seeded kpncorp employees (~{$count} rows) + performance_appraisals table.");
    }
}
