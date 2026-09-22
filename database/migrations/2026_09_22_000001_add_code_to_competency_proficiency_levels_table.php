<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A short code identifying one rung of a competency's proficiency ladder —
     * the same field the competency and its type already carry.
     *
     * Unique **per competency**, not globally: `PL1` is the natural code for a
     * first rung and every competency's ladder starts with one, so a global
     * index would let exactly one competency use it. That matches the rung's
     * name, which the table already scopes the same way.
     *
     * Nullable in the database because the existing rungs have none and there
     * is nothing to backfill them from; the form and the import both require
     * one, so a rung acquires its code the next time its competency is saved.
     * MySQL treats a tuple containing NULL as non-comparable under a unique
     * index, so any number of un-coded rungs can sit in one competency until
     * then.
     */
    public function up(): void
    {
        Schema::table('competency_proficiency_levels', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('competency_id');
            $table->unique(['competency_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('competency_proficiency_levels', function (Blueprint $table) {
            $table->dropUnique(['competency_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
