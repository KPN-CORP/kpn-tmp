<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was actually reached, against the plan's target.
 *
 * A RESULT field, so it sits beside `realization_date` rather than with the
 * planning columns — filing it does not disturb the planning approval, and it
 * is written through SubmitIdpResultRequest, never through the plan form.
 *
 * Counted in the plan's own `uom`: there is deliberately no second unit column,
 * because an achievement measured in a different unit from its target could not
 * be compared with it, which is the whole point of recording it.
 *
 * Nullable, like `target`: the 430 results already filed predate the field.
 * Such a row still displays; it acquires an achievement when it is next filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('individual_development_plans', function (Blueprint $table) {
            $table->decimal('achievement', 12, 2)->nullable()->after('realization_date');
        });
    }

    public function down(): void
    {
        Schema::table('individual_development_plans', function (Blueprint $table) {
            $table->dropColumn('achievement');
        });
    }
};
