<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File proficiency levels under a competency type, the way competencies and
 * development programs already are.
 *
 * The type is nullable — existing levels predate the classification and stay
 * global until one is picked. Because a typed level list is per type, the name
 * is now unique inside its type rather than across the whole table (so "Level
 * 1" can exist under both Soft and Technical). MySQL treats NULLs as distinct
 * in a unique index, so uniqueness among untyped levels is enforced in the
 * master validation rule instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proficiency_levels', function (Blueprint $table) {
            $table->foreignId('competency_type_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();

            $table->dropUnique('proficiency_levels_name_en_unique');
            $table->unique(['competency_type_id', 'name_en']);
        });
    }

    public function down(): void
    {
        Schema::table('proficiency_levels', function (Blueprint $table) {
            $table->dropUnique('proficiency_levels_competency_type_id_name_en_unique');

            $table->dropForeign(['competency_type_id']);
            $table->dropColumn('competency_type_id');

            $table->unique('name_en');
        });
    }
};
