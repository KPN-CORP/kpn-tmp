<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('development_models', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
            $table->string('name_id')->nullable()->after('name_en');
        });

        // Backfill both localized names from the existing canonical name.
        DB::table('development_models')->update([
            'name_en' => DB::raw('name'),
            'name_id' => DB::raw('name'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('development_models', function (Blueprint $table) {
            $table->dropColumn(['name_en', 'name_id']);
        });
    }
};
