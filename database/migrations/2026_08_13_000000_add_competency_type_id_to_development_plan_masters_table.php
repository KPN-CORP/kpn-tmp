<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Competency types are stored as `development_plan_masters` rows with
     * type='competency_type' (name + bilingual description, reusing the
     * localized value/description columns). This nullable self-referencing
     * column links a competency_name row to the competency_type it belongs to.
     */
    public function up(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->foreignId('competency_type_id')
                ->nullable()
                ->after('development_model_id')
                ->constrained('development_plan_masters')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->dropForeign(['competency_type_id']);
            $table->dropColumn('competency_type_id');
        });
    }
};
