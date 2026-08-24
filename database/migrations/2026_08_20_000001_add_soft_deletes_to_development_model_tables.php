<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Development model packages (and the models inside them) are now soft
     * deleted: removing a package keeps the historical row so any IDP that
     * pointed at its models can still resolve them, and the delete can be undone.
     */
    public function up(): void
    {
        Schema::table('development_model_packages', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('development_models', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('development_model_packages', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('development_models', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
