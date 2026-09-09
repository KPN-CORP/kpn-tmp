<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A short code identifying a competency type, alongside its bilingual name
     * — the same shape kpncorp's own masters use (`master_bisnisunits` keys on
     * `kode_bisnis`).
     *
     * Nullable in the database because the existing types have none and there
     * is nothing to backfill them from; the form requires one, so a type
     * acquires its code the next time it is saved. Unique so two types can
     * never answer to the same code — MySQL allows any number of NULLs under a
     * unique index, which is what lets the un-coded rows coexist.
     */
    public function up(): void
    {
        Schema::table('competency_types', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('competency_types', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
