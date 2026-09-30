<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan's quantitative target: how much, in what unit.
 *
 * Both nullable, and nullable they stay: every plan that exists predates the
 * field, and requiring it would make each of them uneditable. The form pairs
 * them instead — a target with no unit is meaningless, and a unit with no
 * target says nothing — which is enforced in the FormRequest and the importer.
 *
 * `target` is decimal rather than an integer: a target reads as "3 times" far
 * more often than "3.5", but "95.5 %" and "1.5 days" are ordinary enough that
 * rounding them away would be a silent data loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('individual_development_plans', function (Blueprint $table) {
            $table->decimal('target', 12, 2)->nullable()->after('expected_outcome');
            // The `UnitOfMeasurement` backed value, not a label — so the same
            // row reads "Times" in English and "Kali" in Indonesian.
            $table->string('uom', 50)->nullable()->after('target');
        });
    }

    public function down(): void
    {
        Schema::table('individual_development_plans', function (Blueprint $table) {
            $table->dropColumn(['target', 'uom']);
        });
    }
};
