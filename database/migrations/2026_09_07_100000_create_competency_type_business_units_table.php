<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A competency type is scoped to the business units it applies to.
 *
 * A type applies to several units in practice, so this is a child table of raw
 * corporate strings rather than a column — the same shape
 * `training_business_units` and `implementation_business_units` already have.
 * The value is the business-unit NAME from kpncorp's `master_bisnisunits`
 * (which is what `employees.group_company` holds), not a foreign key: the
 * corporate master lives on another connection.
 *
 * Nothing to backfill — the field is new, so existing types simply have no
 * units until they are edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competency_type_business_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competency_type_id')->constrained()->cascadeOnDelete();
            $table->string('business_unit');
            $table->unique(['competency_type_id', 'business_unit'], 'competency_type_business_units_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_type_business_units');
    }
};
