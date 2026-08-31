<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split `development_plan_masters` into one table per entity.
 *
 * That table was single-table inheritance: a `type` discriminator over seven
 * unrelated entities sharing 21 mostly-nullable columns, two json id lists
 * standing in for many-to-many links, and a `proficiency_level_id` that meant
 * "the level I belong to" on key-behavior rows but "a level I selected" on
 * every other row. This migration only creates the replacement structure; the
 * companion migration copies the data across, preserving ids.
 *
 * Naming note: the old canonical column was `value` with `value_en`/`value_id`
 * bolted on later and left mostly blank. Here `name_en` is the single canonical
 * name (it is what `individual_development_plans` stores verbatim) and
 * `name_id` is the optional Indonesian display name.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Standalone masters -------------------------------------------------

        Schema::create('competency_types', function (Blueprint $table) {
            $table->id();
            $table->string('name_en')->unique();
            $table->string('name_id')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_id')->nullable();
            $table->timestamps();
        });

        Schema::create('proficiency_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name_en')->unique();
            $table->string('name_id')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_id')->nullable();
            $table->timestamps();
        });

        Schema::create('review_tools', function (Blueprint $table) {
            $table->id();
            $table->string('name_en')->unique();
            $table->string('name_id')->nullable();
            $table->timestamps();
        });

        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->string('name_en')->unique();
            $table->string('name_id')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_id')->nullable();
            $table->timestamps();
        });

        // --- Key behaviors: owned by exactly one proficiency level --------------

        Schema::create('key_behaviors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proficiency_level_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('name_en');
            $table->string('name_id')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_id')->nullable();
            $table->timestamps();

            // A behavior name only has to be unique inside its own level.
            $table->unique(['proficiency_level_id', 'name_en']);
        });

        // --- Competencies -------------------------------------------------------

        Schema::create('competencies', function (Blueprint $table) {
            $table->id();
            // Nullable: legacy rows predate the classification requirement.
            $table->foreignId('competency_type_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->string('name_en')->unique();
            $table->string('name_id')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_id')->nullable();
            $table->timestamps();
        });

        // Replaces `proficiency_level_ids` json.
        Schema::create('competency_proficiency_level', function (Blueprint $table) {
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proficiency_level_id')->constrained()->cascadeOnDelete();
            $table->primary(['competency_id', 'proficiency_level_id']);
        });

        // Replaces `key_behavior_ids` json.
        Schema::create('competency_key_behavior', function (Blueprint $table) {
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('key_behavior_id')->constrained()->cascadeOnDelete();
            $table->primary(['competency_id', 'key_behavior_id']);
        });

        // --- Development programs ----------------------------------------------

        Schema::create('development_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('development_model_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            // Nullable: legacy rows predate the classification requirement.
            $table->foreignId('competency_type_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            // Null on an "Others" program, which free-types its level instead.
            $table->foreignId('proficiency_level_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            // Program names run to several hundred characters: they read as
            // activity descriptions, so they cannot be short strings.
            $table->text('name_en');
            $table->text('name_id')->nullable();
            $table->text('custom_competency')->nullable();
            $table->string('custom_proficiency_level')->nullable();
            $table->timestamps();
        });

        // TEXT needs a prefix length to be indexable, which the schema builder
        // cannot express. Uniqueness stays enforced in validation, as before.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE development_programs ADD INDEX development_programs_name_en_index (name_en(191))');
        }

        // Replaces the `related_program` json id list that was stored on the
        // competency side and diffed in PHP on every save.
        Schema::create('competency_development_program', function (Blueprint $table) {
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('development_program_id')->constrained()->cascadeOnDelete();
            $table->primary(['competency_id', 'development_program_id']);
        });

        // Replaces the `grades` json list. The grade is a raw kpncorp
        // `job_level` string, so it is a value and not a foreign key.
        Schema::create('development_program_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('development_program_id')->constrained()->cascadeOnDelete();
            $table->string('grade');
            $table->unique(['development_program_id', 'grade'], 'dev_program_grade_unique');
        });

        // --- Master implementation ---------------------------------------------

        Schema::create('competency_implementations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competency_type_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            // Restrict, not cascade: deleting a competency must not silently
            // take its implementations with it.
            $table->foreignId('competency_id')->constrained()->restrictOnDelete();
            // Corporate org scope, all raw kpncorp strings. Null means "any".
            $table->string('grade')->nullable();
            $table->string('business_unit')->nullable();
            $table->string('job_family')->nullable();
            $table->string('function_name')->nullable();
            $table->string('position')->nullable();
            $table->timestamps();
        });

        // Short table/column names here keep the generated constraint names
        // inside MySQL's 64-character identifier limit.
        Schema::create('implementation_proficiency_level', function (Blueprint $table) {
            $table->foreignId('implementation_id')
                ->constrained('competency_implementations')
                ->cascadeOnDelete();
            $table->foreignId('proficiency_level_id')->constrained()->cascadeOnDelete();
            $table->primary(['implementation_id', 'proficiency_level_id']);
        });
    }

    public function down(): void
    {
        // Children first so the foreign keys drop cleanly.
        Schema::dropIfExists('implementation_proficiency_level');
        Schema::dropIfExists('competency_implementations');
        Schema::dropIfExists('development_program_grades');
        Schema::dropIfExists('competency_development_program');
        Schema::dropIfExists('development_programs');
        Schema::dropIfExists('competency_key_behavior');
        Schema::dropIfExists('competency_proficiency_level');
        Schema::dropIfExists('competencies');
        Schema::dropIfExists('key_behaviors');
        Schema::dropIfExists('trainings');
        Schema::dropIfExists('review_tools');
        Schema::dropIfExists('proficiency_levels');
        Schema::dropIfExists('competency_types');
    }
};
