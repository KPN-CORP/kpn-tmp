<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the fixed layer_1..layer_5 columns with a single ordered JSON array,
 * so an employee's approval chain can have any number of layers (add/remove
 * from the UI, no hard cap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_superiors', function (Blueprint $table) {
            $table->json('layers')->nullable()->after('employee_id');
        });

        Schema::table('approval_superiors', function (Blueprint $table) {
            $table->dropColumn(['layer_1_id', 'layer_2_id', 'layer_3_id', 'layer_4_id', 'layer_5_id']);
        });
    }

    public function down(): void
    {
        Schema::table('approval_superiors', function (Blueprint $table) {
            $table->string('layer_1_id', 25)->nullable()->after('employee_id');
            $table->string('layer_2_id', 25)->nullable()->after('layer_1_id');
            $table->string('layer_3_id', 25)->nullable()->after('layer_2_id');
            $table->string('layer_4_id', 25)->nullable()->after('layer_3_id');
            $table->string('layer_5_id', 25)->nullable()->after('layer_4_id');
        });

        Schema::table('approval_superiors', function (Blueprint $table) {
            $table->dropColumn('layers');
        });
    }
};
