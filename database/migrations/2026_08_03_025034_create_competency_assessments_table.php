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
        Schema::create('competency_assessments', function (Blueprint $table) {
            $table->id();
            // employee_id is the corporate HR id (string, keeps leading zeros).
            $table->string('employee_id', 25)->index();
            $table->date('assessment_date');
            $table->string('matrix_grade', 10)->nullable();
            $table->string('proposed_grade', 10)->nullable();
            $table->string('priority_for_development', 5)->nullable(); // Yes/No
            $table->unsignedTinyInteger('synergized_team_score')->nullable();
            $table->unsignedTinyInteger('integrity_score')->nullable();
            $table->unsignedTinyInteger('growth_score')->nullable();
            $table->unsignedTinyInteger('adaptive_score')->nullable();
            $table->unsignedTinyInteger('passion_score')->nullable();
            $table->unsignedTinyInteger('manage_planning_score')->nullable();
            $table->unsignedTinyInteger('decision_making_score')->nullable();
            $table->unsignedTinyInteger('relationship_building_score')->nullable();
            $table->unsignedTinyInteger('developing_others_score')->nullable();
            $table->string('period', 10)->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competency_assessments');
    }
};
