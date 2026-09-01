<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A master training targets any number of proficiency levels.
 *
 * It started as a single `trainings.proficiency_level_id`. One training covers
 * several levels in practice, so the column becomes a pivot — the same shape
 * `implementation_proficiency_level` already has for master implementations.
 *
 * The existing single values are copied into the pivot before the column goes,
 * and copied back on rollback (a training with several levels keeps its lowest
 * level id, which is all a single column can hold).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proficiency_level_training', function (Blueprint $table) {
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proficiency_level_id')->constrained()->cascadeOnDelete();
            $table->primary(['training_id', 'proficiency_level_id']);
        });

        DB::table('trainings')
            ->whereNotNull('proficiency_level_id')
            ->orderBy('id')
            ->select('id', 'proficiency_level_id')
            ->chunk(500, function ($rows) {
                DB::table('proficiency_level_training')->insertOrIgnore(
                    $rows->map(fn ($row) => [
                        'training_id' => $row->id,
                        'proficiency_level_id' => $row->proficiency_level_id,
                    ])->all()
                );
            });

        Schema::table('trainings', function (Blueprint $table) {
            $table->dropForeign(['proficiency_level_id']);
            $table->dropColumn('proficiency_level_id');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->foreignId('proficiency_level_id')
                ->nullable()
                ->after('competency_id')
                ->constrained()
                ->restrictOnDelete();
        });

        // A single column can only carry one level; keep the lowest id.
        DB::table('proficiency_level_training')
            ->selectRaw('training_id, MIN(proficiency_level_id) as proficiency_level_id')
            ->groupBy('training_id')
            ->get()
            ->each(fn ($row) => DB::table('trainings')
                ->where('id', $row->training_id)
                ->update(['proficiency_level_id' => $row->proficiency_level_id]));

        Schema::dropIfExists('proficiency_level_training');
    }
};
