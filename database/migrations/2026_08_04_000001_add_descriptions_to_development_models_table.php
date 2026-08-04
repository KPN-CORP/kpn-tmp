<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilingual descriptions for each development model, shown as the 70-20-10
 * explainer on the IDP detail page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('development_models', function (Blueprint $table) {
            $table->text('description_en')->nullable()->after('percentage');
            $table->text('description_id')->nullable()->after('description_en');
        });
    }

    public function down(): void
    {
        Schema::table('development_models', function (Blueprint $table) {
            $table->dropColumn(['description_en', 'description_id']);
        });
    }
};
