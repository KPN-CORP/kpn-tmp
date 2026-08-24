<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A competency (competency_name row) may optionally pin one key behavior
     * under its chosen proficiency level. This nullable self-referencing column
     * links to a `key_behavior` row, mirroring `proficiency_level_id`. When set,
     * proficiency_level_id must be its parent level (enforced in the request).
     */
    public function up(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->foreignId('key_behavior_id')
                ->nullable()
                ->after('proficiency_level_id')
                ->constrained('development_plan_masters')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->dropForeign(['key_behavior_id']);
            $table->dropColumn('key_behavior_id');
        });
    }
};
