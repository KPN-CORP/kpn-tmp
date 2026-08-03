<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('matrix_grade_configs', function (Blueprint $table) {
            $table->id();
            $table->year('period')->index();
            $table->string('grade_level', 5);
            $table->unsignedTinyInteger('synergized_team_min')->default(0);
            $table->unsignedTinyInteger('integrity_min')->default(0);
            $table->unsignedTinyInteger('growth_min')->default(0);
            $table->unsignedTinyInteger('adaptive_min')->default(0);
            $table->unsignedTinyInteger('passion_min')->default(0);
            $table->unsignedTinyInteger('manage_planning_min')->default(0);
            $table->unsignedTinyInteger('decision_making_min')->default(0);
            $table->unsignedTinyInteger('relationship_building_min')->default(0);
            $table->unsignedTinyInteger('developing_others_min')->default(0);
            $table->string('overall_status_min', 50)->nullable();
            $table->timestamps();

            $table->unique(['period', 'grade_level'], 'matrix_period_grade_level_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matrix_grade_configs');
    }
};
