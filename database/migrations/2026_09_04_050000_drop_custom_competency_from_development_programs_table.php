<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A development program on the catch-all "Others" competency type used to
     * free-type the competencies it develops. It now picks a real competency
     * master filed under that type, exactly like a program on any other type,
     * so the free-text column has nothing left to hold.
     *
     * The free-typed proficiency level stays: "Others" still has no
     * implementation map to draw a level from.
     */
    public function up(): void
    {
        Schema::table('development_programs', function (Blueprint $table) {
            $table->dropColumn('custom_competency');
        });
    }

    public function down(): void
    {
        Schema::table('development_programs', function (Blueprint $table) {
            $table->text('custom_competency')->nullable()->after('proficiency_level_id');
        });
    }
};
