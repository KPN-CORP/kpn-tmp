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
        Schema::create('individual_development_plans', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id', 25)->index();
            $table->foreignId('development_model_id')->index()->nullable()->constrained('development_models')->onDelete('set null');            
            $table->string('competency_type');
            $table->string('competency_name');
            $table->string('review_tools')->nullable();
            $table->longText('development_program');
            $table->longText('expected_outcome');
            $table->date('time_frame_start')->nullable();
            $table->date('time_frame_end')->nullable();
            $table->date('realization_date')->nullable();
            $table->text('result_evidence')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('individual_development_plans');
    }
};
