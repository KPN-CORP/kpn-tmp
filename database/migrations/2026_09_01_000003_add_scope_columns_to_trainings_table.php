<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope a master training to what it develops and who it is for.
 *
 * A training used to be nothing but a bilingual name + description. It now
 * carries the competency it builds (through its competency type), the
 * proficiency level it targets, and the corporate scope it is offered in
 * (business unit -> work location, both raw kpncorp strings, deliberately not
 * foreign keys across the connection).
 *
 * Every column is nullable: the catalogue predates the scope, and a training
 * with none set is simply unscoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            // Restrict, not cascade: deleting a master must not silently take
            // the trainings that reference it with it. `IdpMasterService`
            // blocks those deletes with a readable message first.
            $table->foreignId('competency_type_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('competency_id')
                ->nullable()
                ->after('competency_type_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('proficiency_level_id')
                ->nullable()
                ->after('competency_id')
                ->constrained()
                ->restrictOnDelete();
            // Corporate scope, raw kpncorp strings. Null means "any".
            $table->string('business_unit')->nullable()->after('description_id');
            $table->string('work_location')->nullable()->after('business_unit');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropForeign(['competency_type_id']);
            $table->dropForeign(['competency_id']);
            $table->dropForeign(['proficiency_level_id']);
            $table->dropColumn([
                'competency_type_id',
                'competency_id',
                'proficiency_level_id',
                'business_unit',
                'work_location',
            ]);
        });
    }
};
