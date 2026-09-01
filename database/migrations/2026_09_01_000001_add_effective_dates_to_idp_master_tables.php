<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the dated IDP masters an effective period.
 *
 * Competencies, proficiency levels and review tools are revised over time: a
 * competency is retired, a level is renamed for a new cycle, a review tool is
 * introduced mid-year. Until now a master was either present or deleted, and
 * deleting one is blocked while any IDP still names it. The period lets a
 * master be retired for *new* work without disturbing the rows that reference
 * it.
 *
 * Both ends are nullable and mean "unbounded": a null start is effective from
 * always, a null end never expires. Existing rows therefore keep behaving
 * exactly as they did.
 */
return new class extends Migration
{
    /**
     * The masters that carry an effective period.
     *
     * @var list<string>
     */
    private array $tables = ['competencies', 'proficiency_levels', 'review_tools'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->date('effective_start_date')->nullable()->after('name_id');
                $blueprint->date('effective_end_date')->nullable()->after('effective_start_date');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['effective_start_date', 'effective_end_date']);
            });
        }
    }
};
