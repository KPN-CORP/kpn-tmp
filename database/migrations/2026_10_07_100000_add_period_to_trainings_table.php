<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The year a master training belongs to — the catalogue is planned per
     * year, so "Effective Communication Workshop" in 2026 is a different entry
     * from the one in 2027.
     *
     * A plain year rather than a date range: the client asks for a period in
     * years, and a range would invite a training that runs from mid-2026 to
     * mid-2027, which no screen could then file under either year.
     *
     * `unsignedSmallInteger` holds 0-65535, which is every year this will ever
     * see; the 2000-2100 window is a validation rule rather than a column
     * constraint, so widening it later needs no migration.
     *
     * Nullable in the database because the existing trainings have none and
     * there is nothing to backfill them from — the form and the import both
     * require one, so a training acquires its period the next time it is saved.
     * Until then the list shows a dash, the same trade-off the masters' `code`
     * columns took.
     */
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->unsignedSmallInteger('period')->nullable()->after('description_id');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn('period');
        });
    }
};
