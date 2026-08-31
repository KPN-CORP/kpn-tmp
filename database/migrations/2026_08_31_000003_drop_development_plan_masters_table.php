<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the single-table-inheritance master table now that its rows live in
 * the per-entity tables.
 *
 * `down()` recreates the table empty; the companion data migration's own
 * `down()` runs next in a rollback and refills it, so the pair round-trips.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('development_plan_masters');
    }

    public function down(): void
    {
        Schema::create('development_plan_masters', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->longText('value')->nullable();
            $table->longText('value_en')->nullable();
            $table->longText('value_id')->nullable();
            $table->longText('description_en')->nullable();
            $table->longText('description_id')->nullable();
            $table->longText('related_program')->nullable();
            $table->foreignId('development_model_id')->nullable()->index()
                ->constrained('development_models')->nullOnDelete();
            $table->foreignId('competency_type_id')->nullable()
                ->constrained('development_plan_masters')->nullOnDelete();
            $table->foreignId('competency_name_id')->nullable()
                ->constrained('development_plan_masters')->nullOnDelete();
            $table->foreignId('proficiency_level_id')->nullable()
                ->constrained('development_plan_masters')->nullOnDelete();
            $table->json('proficiency_level_ids')->nullable();
            $table->foreignId('key_behavior_id')->nullable()
                ->constrained('development_plan_masters')->nullOnDelete();
            $table->json('key_behavior_ids')->nullable();
            $table->text('custom_competency')->nullable();
            $table->string('custom_proficiency_level')->nullable();
            $table->string('business_unit')->nullable();
            $table->string('grade')->nullable();
            $table->json('grades')->nullable();
            $table->string('job_family')->nullable();
            $table->string('function_name')->nullable();
            $table->string('position')->nullable();
            $table->timestamps();
        });
    }
};
