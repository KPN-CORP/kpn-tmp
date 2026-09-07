<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proficiency levels (and the key behaviors under them) that a competency OWNS,
 * typed in on the competency's own form.
 *
 * Until now a competency selected rows from the shared `proficiency_levels` /
 * `key_behaviors` masters through the `competency_proficiency_level` and
 * `competency_key_behavior` pivots. A competency's levels are its own thing in
 * practice — an ordered ladder with its own wording — so they become child rows
 * here: free-typed, bilingual, sequenced, each switchable on/off with the same
 * audited history the masters have.
 *
 * Note the near-identical names: `competency_proficiency_level` (singular) is
 * the OLD pivot at master rows; `competency_proficiency_levels` (plural) is the
 * new owned table. The pivots are deliberately left in place — Master
 * Implementation and the development-program screen still read them — they are
 * simply no longer written by the competency form.
 *
 * The existing selections are copied across so nothing the form used to show is
 * lost. `down()` drops the two tables; the pivots were never touched, so the
 * old wiring is still there to fall back on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competency_proficiency_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->string('name_en');
            $table->string('name_id')->nullable();
            // Where the level sits on the ladder (1, 2, 3 …). Editable, so no
            // unique index: swapping two rows' numbers in one save would
            // collide with one, even though the end state is valid. The
            // request validates that they are distinct instead.
            $table->unsignedInteger('sequence')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['competency_id', 'name_en'], 'competency_proficiency_levels_unique');
        });

        Schema::create('competency_key_behaviors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competency_proficiency_level_id')
                ->constrained('competency_proficiency_levels')
                ->cascadeOnDelete();
            $table->string('name_en');
            $table->string('name_id')->nullable();
            $table->timestamps();
            $table->unique(
                ['competency_proficiency_level_id', 'name_en'],
                'competency_key_behaviors_unique'
            );
        });

        $this->copyFromPivots();
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_key_behaviors');
        Schema::dropIfExists('competency_proficiency_levels');
    }

    /**
     * Turn each competency's pinned master levels into owned rows, numbering
     * them in the masters' own order, and carry over the key behaviors the
     * competency had picked under each.
     */
    private function copyFromPivots(): void
    {
        $now = now();

        DB::table('competency_proficiency_level as pivot')
            ->join('proficiency_levels as pl', 'pl.id', '=', 'pivot.proficiency_level_id')
            ->orderBy('pivot.competency_id')
            ->orderBy('pl.id')
            ->get([
                'pivot.competency_id',
                'pl.id as level_id',
                'pl.name_en',
                'pl.name_id',
                'pl.is_active',
            ])
            ->groupBy('competency_id')
            ->each(function ($levels, $competencyId) use ($now) {
                $sequence = 0;

                foreach ($levels as $level) {
                    $ownedId = DB::table('competency_proficiency_levels')->insertGetId([
                        'competency_id' => $competencyId,
                        'name_en' => $level->name_en,
                        'name_id' => $level->name_id,
                        'sequence' => ++$sequence,
                        'is_active' => $level->is_active,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    // Only the behaviors this competency actually picked, and
                    // only those belonging to the level being copied.
                    $behaviors = DB::table('competency_key_behavior as pivot')
                        ->join('key_behaviors as kb', 'kb.id', '=', 'pivot.key_behavior_id')
                        ->where('pivot.competency_id', $competencyId)
                        ->where('kb.proficiency_level_id', $level->level_id)
                        ->orderBy('kb.id')
                        ->get(['kb.name_en', 'kb.name_id']);

                    if ($behaviors->isEmpty()) {
                        continue;
                    }

                    DB::table('competency_key_behaviors')->insert(
                        $behaviors->map(fn ($behavior) => [
                            'competency_proficiency_level_id' => $ownedId,
                            'name_en' => $behavior->name_en,
                            'name_id' => $behavior->name_id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all()
                    );
                }
            });
    }
};
