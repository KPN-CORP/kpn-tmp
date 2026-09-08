<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A development program carries a bilingual description, like every other
 * master with a name worth explaining.
 *
 * It arrives with the name: a program filed under a model that draws from the
 * Master Training catalogue copies both off the training, and one under any
 * other model types both by hand. TEXT rather than a string for the same reason
 * the name is - a program reads as an activity description.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('development_programs', function (Blueprint $table) {
            $table->text('description_en')->nullable()->after('name_id');
            $table->text('description_id')->nullable()->after('description_en');
        });
    }

    public function down(): void
    {
        Schema::table('development_programs', function (Blueprint $table) {
            $table->dropColumn(['description_en', 'description_id']);
        });
    }
};
