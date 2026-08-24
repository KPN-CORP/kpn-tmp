<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proficiency levels are stored as `development_plan_masters` rows with
     * type='proficiency_level' (name + bilingual description). This nullable
     * self-referencing column links a competency_name row to the proficiency
     * level chosen for it, mirroring `competency_type_id`.
     */
    public function up(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->foreignId('proficiency_level_id')
                ->nullable()
                ->after('competency_type_id')
                ->constrained('development_plan_masters')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->dropForeign(['proficiency_level_id']);
            $table->dropColumn('proficiency_level_id');
        });
    }
};
