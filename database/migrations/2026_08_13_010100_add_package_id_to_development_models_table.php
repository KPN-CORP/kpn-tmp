<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link every development model to a package. Existing models are wrapped
     * into one auto-created default (current) package so nothing breaks.
     */
    public function up(): void
    {
        Schema::table('development_models', function (Blueprint $table) {
            $table->foreignId('development_model_package_id')
                ->nullable()
                ->after('id')
                ->constrained('development_model_packages')
                ->cascadeOnDelete();
        });

        // Backfill: if any models exist without a package, bundle them all into
        // one ongoing "Default" package spanning from the start of this year.
        $orphanCount = DB::table('development_models')
            ->whereNull('development_model_package_id')
            ->count();

        if ($orphanCount > 0) {
            $packageId = DB::table('development_model_packages')->insertGetId([
                'name' => 'Default',
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => null,
                'is_current' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('development_models')
                ->whereNull('development_model_package_id')
                ->update(['development_model_package_id' => $packageId]);
        }
    }

    public function down(): void
    {
        Schema::table('development_models', function (Blueprint $table) {
            $table->dropForeign(['development_model_package_id']);
            $table->dropColumn('development_model_package_id');
        });
    }
};
