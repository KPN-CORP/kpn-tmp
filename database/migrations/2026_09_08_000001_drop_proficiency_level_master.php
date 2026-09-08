<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the shared proficiency-level master.
 *
 * A proficiency level is not a catalogue of its own: it is a rung on one
 * competency's ladder, which is what `competency_proficiency_levels` has stored
 * since the competency form started owning them. The three screens that still
 * selected from the shared master — Master Implementation, Master Training and
 * the development-program form — all pick a competency first, so each of them
 * now reads that competency's own ladder instead.
 *
 * So `proficiency_levels` and its `key_behaviors` children go, together with
 * the two pivots that pinned master rows onto a competency. The three
 * references keep their column names and are remapped onto the owning
 * competency's rung of the same name; a reference with no matching rung is
 * dropped, since nothing could resolve it.
 *
 * `down()` restores the MEANING, not the original rows: the master is rebuilt
 * from the competency ladders, one row per (competency type, name), and the
 * references are pointed back at it. Descriptions and the original ids are not
 * recoverable — nothing carries them any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Master level id => the name it was known by, so a reference can be
        // resolved onto the owning competency's rung of the same name.
        $masterNames = DB::table('proficiency_levels')->pluck('name_en', 'id');

        // competency id => [lowercased rung name => rung id].
        $rungs = [];
        foreach (DB::table('competency_proficiency_levels')->get() as $rung) {
            $rungs[$rung->competency_id][mb_strtolower($rung->name_en)] = $rung->id;
        }

        $rungFor = function ($competencyId, $masterId) use ($masterNames, $rungs): ?int {
            $name = $masterNames[$masterId] ?? null;

            if ($competencyId === null || $name === null) {
                return null;
            }

            return $rungs[$competencyId][mb_strtolower($name)] ?? null;
        };

        // --- implementation_proficiency_level: keyed on the mapping's competency ---
        $implCompetency = DB::table('competency_implementations')->pluck('competency_id', 'id');

        $implRows = DB::table('implementation_proficiency_level')->get()
            ->map(fn ($row) => [
                'implementation_id' => $row->implementation_id,
                'proficiency_level_id' => $rungFor(
                    $implCompetency[$row->implementation_id] ?? null,
                    $row->proficiency_level_id,
                ),
            ])
            ->filter(fn (array $row) => $row['proficiency_level_id'] !== null)
            ->unique(fn (array $row) => $row['implementation_id'].':'.$row['proficiency_level_id'])
            ->values()
            ->all();

        // --- proficiency_level_training: keyed on the training's competency ---
        $trainingCompetency = DB::table('trainings')->pluck('competency_id', 'id');

        $trainingRows = DB::table('proficiency_level_training')->get()
            ->map(fn ($row) => [
                'training_id' => $row->training_id,
                'proficiency_level_id' => $rungFor(
                    $trainingCompetency[$row->training_id] ?? null,
                    $row->proficiency_level_id,
                ),
            ])
            ->filter(fn (array $row) => $row['proficiency_level_id'] !== null)
            ->unique(fn (array $row) => $row['training_id'].':'.$row['proficiency_level_id'])
            ->values()
            ->all();

        // --- development_programs.proficiency_level_id: keyed on the single
        // competency the program develops. ---
        $programCompetency = DB::table('competency_development_program')
            ->get()
            ->groupBy('development_program_id')
            ->map(fn ($rows) => $rows->first()->competency_id);

        $programLevels = [];
        foreach (DB::table('development_programs')->whereNotNull('proficiency_level_id')->get() as $program) {
            $programLevels[$program->id] = $rungFor(
                $programCompetency[$program->id] ?? null,
                $program->proficiency_level_id,
            );
        }

        // Repoint every reference, then drop the master itself.
        Schema::table('implementation_proficiency_level', function (Blueprint $table) {
            $table->dropForeign(['proficiency_level_id']);
        });
        Schema::table('proficiency_level_training', function (Blueprint $table) {
            $table->dropForeign(['proficiency_level_id']);
        });
        Schema::table('development_programs', function (Blueprint $table) {
            $table->dropForeign(['proficiency_level_id']);
        });

        DB::table('implementation_proficiency_level')->delete();
        if ($implRows !== []) {
            DB::table('implementation_proficiency_level')->insert($implRows);
        }

        DB::table('proficiency_level_training')->delete();
        if ($trainingRows !== []) {
            DB::table('proficiency_level_training')->insert($trainingRows);
        }

        foreach ($programLevels as $programId => $levelId) {
            DB::table('development_programs')->where('id', $programId)
                ->update(['proficiency_level_id' => $levelId]);
        }

        Schema::dropIfExists('competency_key_behavior');
        Schema::dropIfExists('competency_proficiency_level');
        Schema::dropIfExists('key_behaviors');
        Schema::dropIfExists('proficiency_levels');

        Schema::table('implementation_proficiency_level', function (Blueprint $table) {
            $table->foreign('proficiency_level_id')
                ->references('id')->on('competency_proficiency_levels')
                ->cascadeOnDelete();
        });
        Schema::table('proficiency_level_training', function (Blueprint $table) {
            $table->foreign('proficiency_level_id')
                ->references('id')->on('competency_proficiency_levels')
                ->cascadeOnDelete();
        });
        Schema::table('development_programs', function (Blueprint $table) {
            $table->foreign('proficiency_level_id')
                ->references('id')->on('competency_proficiency_levels')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::create('proficiency_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competency_type_id')->nullable()
                ->constrained('competency_types')->restrictOnDelete();
            $table->string('name_en');
            $table->string('name_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('description_en')->nullable();
            $table->text('description_id')->nullable();
            $table->timestamps();
            $table->unique(['competency_type_id', 'name_en']);
        });

        Schema::create('key_behaviors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proficiency_level_id')
                ->constrained('proficiency_levels')->restrictOnDelete();
            $table->string('name_en');
            $table->string('name_id')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_id')->nullable();
            $table->timestamps();
            $table->unique(['proficiency_level_id', 'name_en']);
        });

        Schema::create('competency_proficiency_level', function (Blueprint $table) {
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proficiency_level_id')
                ->constrained('proficiency_levels')->cascadeOnDelete();
            $table->primary(['competency_id', 'proficiency_level_id']);
        });

        Schema::create('competency_key_behavior', function (Blueprint $table) {
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('key_behavior_id')
                ->constrained('key_behaviors')->cascadeOnDelete();
            $table->primary(['competency_id', 'key_behavior_id']);
        });

        $now = now();

        // Rebuild the master from the ladders: one row per competency type +
        // name, since that is what the master was keyed on.
        $competencyType = DB::table('competencies')->pluck('competency_type_id', 'id');

        // owned rung id => rebuilt master level id.
        $masterFor = [];
        // "type|name" => rebuilt master level id.
        $byKey = [];
        // owned rung id => the competency that owns it.
        $ownerOf = [];

        foreach (DB::table('competency_proficiency_levels')->orderBy('id')->get() as $rung) {
            $typeId = $competencyType[$rung->competency_id] ?? null;
            $key = ($typeId ?? '-').'|'.mb_strtolower($rung->name_en);

            $byKey[$key] ??= DB::table('proficiency_levels')->insertGetId([
                'competency_type_id' => $typeId,
                'name_en' => $rung->name_en,
                'name_id' => $rung->name_id,
                'is_active' => $rung->is_active,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $masterFor[$rung->id] = $byKey[$key];
            $ownerOf[$rung->id] = $rung->competency_id;

            DB::table('competency_proficiency_level')->insertOrIgnore([
                'competency_id' => $rung->competency_id,
                'proficiency_level_id' => $byKey[$key],
            ]);
        }

        // The behaviors under each rebuilt level, and the competencies that
        // observed them.
        $behaviorByKey = [];

        foreach (DB::table('competency_key_behaviors')->orderBy('id')->get() as $behavior) {
            $rungId = $behavior->competency_proficiency_level_id;
            $levelId = $masterFor[$rungId] ?? null;

            if ($levelId === null) {
                continue;
            }

            $key = $levelId.'|'.mb_strtolower($behavior->name_en);

            $behaviorByKey[$key] ??= DB::table('key_behaviors')->insertGetId([
                'proficiency_level_id' => $levelId,
                'name_en' => $behavior->name_en,
                'name_id' => $behavior->name_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('competency_key_behavior')->insertOrIgnore([
                'competency_id' => $ownerOf[$rungId],
                'key_behavior_id' => $behaviorByKey[$key],
            ]);
        }

        // Point the three references back at the rebuilt master.
        Schema::table('implementation_proficiency_level', function (Blueprint $table) {
            $table->dropForeign(['proficiency_level_id']);
        });
        Schema::table('proficiency_level_training', function (Blueprint $table) {
            $table->dropForeign(['proficiency_level_id']);
        });
        Schema::table('development_programs', function (Blueprint $table) {
            $table->dropForeign(['proficiency_level_id']);
        });

        $remap = function (string $table, string $key) use ($masterFor) {
            $rows = DB::table($table)->get()
                ->map(fn ($row) => [
                    $key => $row->{$key},
                    'proficiency_level_id' => $masterFor[$row->proficiency_level_id] ?? null,
                ])
                ->filter(fn (array $row) => $row['proficiency_level_id'] !== null)
                ->unique(fn (array $row) => $row[$key].':'.$row['proficiency_level_id'])
                ->values()
                ->all();

            DB::table($table)->delete();

            if ($rows !== []) {
                DB::table($table)->insert($rows);
            }
        };

        $remap('implementation_proficiency_level', 'implementation_id');
        $remap('proficiency_level_training', 'training_id');

        foreach (DB::table('development_programs')->whereNotNull('proficiency_level_id')->get() as $program) {
            DB::table('development_programs')->where('id', $program->id)->update([
                'proficiency_level_id' => $masterFor[$program->proficiency_level_id] ?? null,
            ]);
        }

        Schema::table('implementation_proficiency_level', function (Blueprint $table) {
            $table->foreign('proficiency_level_id')
                ->references('id')->on('proficiency_levels')->cascadeOnDelete();
        });
        Schema::table('proficiency_level_training', function (Blueprint $table) {
            $table->foreign('proficiency_level_id')
                ->references('id')->on('proficiency_levels')->cascadeOnDelete();
        });
        Schema::table('development_programs', function (Blueprint $table) {
            $table->foreign('proficiency_level_id')
                ->references('id')->on('proficiency_levels')->nullOnDelete();
        });
    }
};
