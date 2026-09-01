<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A development program's name can either be typed (bilingual, as before) or
 * taken from the Master Training catalogue. `training_id` records which — null
 * means the name was typed.
 *
 * The name itself still lives on `development_programs.name_en` / `name_id`,
 * copied from the training on save: IDP rows store the program name verbatim,
 * uniqueness is per development model, and every list reads those columns. The
 * FK adds provenance, not a second source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('development_programs', function (Blueprint $table) {
            $table->foreignId('training_id')
                ->nullable()
                ->after('competency_type_id')
                ->constrained()
                // A deleted training leaves the copied name in place and simply
                // drops the provenance; deletion is blocked while a program
                // points here anyway.
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('development_programs', function (Blueprint $table) {
            $table->dropForeign(['training_id']);
            $table->dropColumn('training_id');
        });
    }
};
