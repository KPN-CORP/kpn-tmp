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
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->longText('value_en')->nullable()->after('value');
            $table->longText('value_id')->nullable()->after('value_en');
            $table->longText('description_en')->nullable()->after('value_id');
            $table->longText('description_id')->nullable()->after('description_en');
        });

        // Backfill localized fields from the existing canonical/single columns.
        DB::table('development_plan_masters')->update([
            'value_en' => DB::raw('value'),
            'value_id' => DB::raw('value'),
            'description_en' => DB::raw('description'),
        ]);

        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->longText('description')->nullable()->after('value');
        });

        DB::table('development_plan_masters')->update([
            'description' => DB::raw('description_en'),
        ]);

        Schema::table('development_plan_masters', function (Blueprint $table) {
            $table->dropColumn(['value_en', 'value_id', 'description_en', 'description_id']);
        });
    }
};
