<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copy every `development_plan_masters` row into its new per-entity table.
 *
 * Primary keys are carried across unchanged. Because each `type` occupied a
 * disjoint slice of the old shared sequence, re-using the ids keeps every
 * existing reference valid with no remapping: the self-referencing columns
 * (competency_type_id, proficiency_level_id, key_behavior_id,
 * competency_name_id) and the json id lists all still point at the right rows.
 *
 * Canonical name: the old table gained `value_en`/`value_id` late and left them
 * blank on almost every row, so the true name lives in `value`. It is folded
 * back together here as `name_en = COALESCE(NULLIF(value_en, ''), value)`,
 * which also ends the value/value_en drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('development_plan_masters')) {
            return;
        }

        $rows = DB::table('development_plan_masters')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            return;
        }

        $byType = $rows->groupBy('type');

        // The canonical name, folding the late-added localized column back into
        // the original one.
        $nameEn = fn ($r) => trim((string) $r->value_en) !== '' ? $r->value_en : $r->value;
        $nameId = fn ($r) => trim((string) ($r->value_id ?? '')) !== '' ? $r->value_id : null;

        $base = fn ($r) => [
            'id' => $r->id,
            'name_en' => $nameEn($r),
            'name_id' => $nameId($r),
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];

        $described = fn ($r) => $base($r) + [
            'description_en' => $r->description_en,
            'description_id' => $r->description_id,
        ];

        // A json column may hold a list, a json string, or null depending on how
        // the row was written; normalize all of it to a list of ints.
        $ids = function ($raw): array {
            if ($raw === null || $raw === '') {
                return [];
            }
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }

            return is_array($decoded)
                ? array_values(array_unique(array_map('intval', $decoded)))
                : [];
        };

        DB::transaction(function () use ($byType, $base, $described, $ids) {
            // --- Parents first, so the foreign keys below resolve. ------------

            $this->insertAll('competency_types', $byType->get('competency_type'), $described);
            $this->insertAll('proficiency_levels', $byType->get('proficiency_level'), $described);
            $this->insertAll('review_tools', $byType->get('review_tools'), $base);
            $this->insertAll('trainings', $byType->get('training'), $described);

            // On a key-behavior row, proficiency_level_id was the owning level.
            $this->insertAll('key_behaviors', $byType->get('key_behavior'), fn ($r) => $described($r) + [
                'proficiency_level_id' => $r->proficiency_level_id,
            ]);

            $this->insertAll('competencies', $byType->get('competency_name'), fn ($r) => $described($r) + [
                'competency_type_id' => $r->competency_type_id,
            ]);

            $this->insertAll('development_programs', $byType->get('development_program'), fn ($r) => $base($r) + [
                'development_model_id' => $r->development_model_id,
                'competency_type_id' => $r->competency_type_id,
                'proficiency_level_id' => $r->proficiency_level_id,
                'custom_competency' => $r->custom_competency,
                'custom_proficiency_level' => $r->custom_proficiency_level,
            ]);

            $this->insertAll('competency_implementations', $byType->get('implementation'), fn ($r) => [
                'id' => $r->id,
                'competency_type_id' => $r->competency_type_id,
                'competency_id' => $r->competency_name_id,
                'grade' => $r->grade,
                'business_unit' => $r->business_unit,
                'job_family' => $r->job_family,
                'function_name' => $r->function_name,
                'position' => $r->position,
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ]);

            // --- Json id lists to real link rows ------------------------------

            // Only link to targets that actually exist; the json lists carried no
            // referential integrity, so stale ids are possible.
            $liveLevels = DB::table('proficiency_levels')->pluck('id')->all();
            $liveBehaviors = DB::table('key_behaviors')->pluck('id')->all();
            $livePrograms = DB::table('development_programs')->pluck('id')->all();
            $liveLevels = array_flip($liveLevels);
            $liveBehaviors = array_flip($liveBehaviors);
            $livePrograms = array_flip($livePrograms);

            $competencyLevels = [];
            $competencyBehaviors = [];
            $competencyPrograms = [];

            foreach ($byType->get('competency_name') ?? [] as $r) {
                // Fall back to the singular pin for rows written before the
                // multi-select arrays existed.
                $levels = $ids($r->proficiency_level_ids) ?: array_filter([(int) $r->proficiency_level_id]);
                foreach ($levels as $levelId) {
                    if (isset($liveLevels[$levelId])) {
                        $competencyLevels[] = ['competency_id' => $r->id, 'proficiency_level_id' => $levelId];
                    }
                }

                $behaviors = $ids($r->key_behavior_ids) ?: array_filter([(int) $r->key_behavior_id]);
                foreach ($behaviors as $behaviorId) {
                    if (isset($liveBehaviors[$behaviorId])) {
                        $competencyBehaviors[] = ['competency_id' => $r->id, 'key_behavior_id' => $behaviorId];
                    }
                }

                foreach ($ids($r->related_program) as $programId) {
                    if (isset($livePrograms[$programId])) {
                        $competencyPrograms[] = ['competency_id' => $r->id, 'development_program_id' => $programId];
                    }
                }
            }

            $this->insertChunked('competency_proficiency_level', $competencyLevels);
            $this->insertChunked('competency_key_behavior', $competencyBehaviors);
            $this->insertChunked('competency_development_program', $competencyPrograms);

            $programGrades = [];
            foreach ($byType->get('development_program') ?? [] as $r) {
                $decoded = $r->grades === null || $r->grades === '' ? null : json_decode((string) $r->grades, true);
                $grades = is_array($decoded) ? $decoded : [];
                if (! $grades && trim((string) $r->grade) !== '') {
                    $grades = [$r->grade];
                }

                foreach (array_unique(array_filter(array_map('trim', array_map('strval', $grades)))) as $grade) {
                    $programGrades[] = ['development_program_id' => $r->id, 'grade' => $grade];
                }
            }
            $this->insertChunked('development_program_grades', $programGrades);

            $implLevels = [];
            foreach ($byType->get('implementation') ?? [] as $r) {
                $levels = $ids($r->proficiency_level_ids) ?: array_filter([(int) $r->proficiency_level_id]);
                foreach ($levels as $levelId) {
                    if (isset($liveLevels[$levelId])) {
                        $implLevels[] = ['implementation_id' => $r->id, 'proficiency_level_id' => $levelId];
                    }
                }
            }
            $this->insertChunked('implementation_proficiency_level', $implLevels);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('development_plan_masters')) {
            return;
        }

        $ts = fn ($r) => ['created_at' => $r->created_at, 'updated_at' => $r->updated_at];

        // The name is written back to both columns: `value` was the real
        // canonical, and `value_en` is what the later code expected.
        $named = fn ($r) => ['value' => $r->name_en, 'value_en' => $r->name_en, 'value_id' => $r->name_id];
        $describedBack = fn ($r) => ['description_en' => $r->description_en, 'description_id' => $r->description_id];

        DB::transaction(function () use ($ts, $named, $describedBack) {
            $put = fn (array $rows) => $this->insertChunked('development_plan_masters', $rows);

            $put(DB::table('competency_types')->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'type' => 'competency_type'] + $named($r) + $describedBack($r) + $ts($r))->all());

            $put(DB::table('proficiency_levels')->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'type' => 'proficiency_level'] + $named($r) + $describedBack($r) + $ts($r))->all());

            $put(DB::table('review_tools')->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'type' => 'review_tools'] + $named($r) + $ts($r))->all());

            $put(DB::table('trainings')->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'type' => 'training'] + $named($r) + $describedBack($r) + $ts($r))->all());

            $put(DB::table('key_behaviors')->orderBy('id')->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'type' => 'key_behavior',
                    'proficiency_level_id' => $r->proficiency_level_id,
                ] + $named($r) + $describedBack($r) + $ts($r))->all());

            // Rebuild the json id lists from the link tables.
            $levelsByCompetency = DB::table('competency_proficiency_level')->get()
                ->groupBy('competency_id')->map(fn ($g) => $g->pluck('proficiency_level_id')->map('intval')->values()->all());
            $behaviorsByCompetency = DB::table('competency_key_behavior')->get()
                ->groupBy('competency_id')->map(fn ($g) => $g->pluck('key_behavior_id')->map('intval')->values()->all());
            $programsByCompetency = DB::table('competency_development_program')->get()
                ->groupBy('competency_id')->map(fn ($g) => $g->pluck('development_program_id')->map('strval')->values()->all());

            $put(DB::table('competencies')->orderBy('id')->get()
                ->map(function ($r) use ($named, $describedBack, $ts, $levelsByCompetency, $behaviorsByCompetency, $programsByCompetency) {
                    $levels = $levelsByCompetency->get($r->id, []);
                    $behaviors = $behaviorsByCompetency->get($r->id, []);
                    $programs = $programsByCompetency->get($r->id, []);

                    return [
                        'id' => $r->id,
                        'type' => 'competency_name',
                        'competency_type_id' => $r->competency_type_id,
                        'proficiency_level_ids' => $levels ? json_encode($levels) : null,
                        'proficiency_level_id' => $levels[0] ?? null,
                        'key_behavior_ids' => $behaviors ? json_encode($behaviors) : null,
                        'key_behavior_id' => $behaviors[0] ?? null,
                        'related_program' => json_encode($programs),
                    ] + $named($r) + $describedBack($r) + $ts($r);
                })->all());

            $gradesByProgram = DB::table('development_program_grades')->orderBy('id')->get()
                ->groupBy('development_program_id')->map(fn ($g) => $g->pluck('grade')->values()->all());

            $put(DB::table('development_programs')->orderBy('id')->get()
                ->map(function ($r) use ($named, $ts, $gradesByProgram) {
                    $grades = $gradesByProgram->get($r->id, []);

                    return [
                        'id' => $r->id,
                        'type' => 'development_program',
                        'development_model_id' => $r->development_model_id,
                        'competency_type_id' => $r->competency_type_id,
                        'proficiency_level_id' => $r->proficiency_level_id,
                        'custom_competency' => $r->custom_competency,
                        'custom_proficiency_level' => $r->custom_proficiency_level,
                        'grades' => $grades ? json_encode($grades) : null,
                        'grade' => $grades[0] ?? null,
                    ] + $named($r) + $ts($r);
                })->all());

            $levelsByImplementation = DB::table('implementation_proficiency_level')->get()
                ->groupBy('implementation_id')->map(fn ($g) => $g->pluck('proficiency_level_id')->map('intval')->values()->all());
            $competencyNames = DB::table('competencies')->pluck('name_en', 'id');

            $put(DB::table('competency_implementations')->orderBy('id')->get()
                ->map(function ($r) use ($ts, $levelsByImplementation, $competencyNames) {
                    $levels = $levelsByImplementation->get($r->id, []);
                    // An implementation has no name of its own; the old table
                    // parked the implemented competency's name in `value`.
                    $name = $competencyNames[$r->competency_id] ?? null;

                    return [
                        'id' => $r->id,
                        'type' => 'implementation',
                        'value' => $name,
                        'value_en' => $name,
                        'competency_type_id' => $r->competency_type_id,
                        'competency_name_id' => $r->competency_id,
                        'proficiency_level_ids' => $levels ? json_encode($levels) : null,
                        'proficiency_level_id' => $levels[0] ?? null,
                        'grade' => $r->grade,
                        'business_unit' => $r->business_unit,
                        'job_family' => $r->job_family,
                        'function_name' => $r->function_name,
                        'position' => $r->position,
                    ] + $ts($r);
                })->all());
        });
    }

    /**
     * Insert every row of one old `type` into its new table, via a shaper.
     *
     * @param  iterable<object>|null  $rows
     * @param  callable(object): array<string, mixed>  $shape
     */
    private function insertAll(string $table, $rows, callable $shape): void
    {
        $this->insertChunked($table, collect($rows ?? [])->map($shape)->all());
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insertChunked(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
};
