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
        Schema::create('development_plan_masters', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->longText('value')->nullable();
            $table->longText('related_program')->nullable();
            $table->foreignId('development_model_id')->index()->nullable()->constrained('development_models')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('development_plan_masters');
    }
};
