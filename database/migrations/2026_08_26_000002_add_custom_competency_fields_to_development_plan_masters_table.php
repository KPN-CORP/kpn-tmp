<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a development program's competency type is "Others", its competencies
     * and proficiency level are free-typed rather than picked from the masters.
     * These nullable columns hold that free text; they stay null for a program
     * whose type maps to real competency masters.
     */
    public function up(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->text('custom_competency')->nullable()->after('proficiency_level_id');
            $table->string('custom_proficiency_level')->nullable()->after('custom_competency');
        });
    }

    public function down(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->dropColumn(['custom_competency', 'custom_proficiency_level']);
        });
    }
};
