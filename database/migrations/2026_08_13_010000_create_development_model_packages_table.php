<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A development-model package is a period-scoped bundle of development
     * models (e.g. the 70-20-10 set valid for a given period). When the model
     * mix changes, a new package is created for the new period so existing IDPs
     * keep pointing at the older package's models.
     */
    public function up(): void
    {
        Schema::create('development_model_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            // Null end date = ongoing package (no fixed end).
            $table->date('end_date')->nullable();
            // Manual "this is the ongoing/current package" pin (only one at a
            // time); the active package is normally resolved from the dates.
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('development_model_packages');
    }
};
