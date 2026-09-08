<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A development model can declare that what it develops comes from the Master
 * Training catalogue rather than being written out by hand.
 *
 * Nothing is copied by the flag itself - it records the intent on the model, so
 * the screens that create data under it (development programs today) can take
 * their names from Master Training instead of asking for free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('development_models', function (Blueprint $table) {
            $table->boolean('uses_master_training')->default(false)->after('percentage');
        });
    }

    public function down(): void
    {
        Schema::table('development_models', function (Blueprint $table) {
            $table->dropColumn('uses_master_training');
        });
    }
};
