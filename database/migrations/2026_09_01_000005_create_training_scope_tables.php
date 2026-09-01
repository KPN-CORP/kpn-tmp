<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A master training is offered in any number of business units and work
 * locations.
 *
 * Both started as single `trainings` columns. One training runs across several
 * units and sites in practice, so each becomes a child table of raw kpncorp
 * strings — the same shape `development_program_grades` and
 * `implementation_grades` already have for grades. They are values, not foreign
 * keys: the corporate tables live on another connection.
 *
 * The existing single values are copied into the child tables before the
 * columns go, and copied back on rollback (a training with several keeps its
 * first value alphabetically, which is all a single column can hold).
 */
return new class extends Migration
{
    /**
     * table => [training column, value column].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private array $tables = [
        'training_business_units' => ['business_unit', 'business_unit'],
        'training_work_locations' => ['work_location', 'work_location'],
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => [$column, $valueColumn]) {
            Schema::create($table, function (Blueprint $blueprint) use ($table, $valueColumn) {
                $blueprint->id();
                $blueprint->foreignId('training_id')->constrained()->cascadeOnDelete();
                $blueprint->string($valueColumn);
                $blueprint->unique(['training_id', $valueColumn], $table.'_unique');
            });

            DB::table('trainings')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderBy('id')
                ->select('id', $column)
                ->chunk(500, function ($rows) use ($table, $column, $valueColumn) {
                    DB::table($table)->insertOrIgnore(
                        $rows->map(fn ($row) => [
                            'training_id' => $row->id,
                            $valueColumn => $row->{$column},
                        ])->all()
                    );
                });
        }

        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn(['business_unit', 'work_location']);
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->string('business_unit')->nullable()->after('description_id');
            $table->string('work_location')->nullable()->after('business_unit');
        });

        // A single column can only carry one value; keep the first alphabetically.
        foreach ($this->tables as $table => [$column, $valueColumn]) {
            DB::table($table)
                ->selectRaw("training_id, MIN({$valueColumn}) as value")
                ->groupBy('training_id')
                ->get()
                ->each(fn ($row) => DB::table('trainings')
                    ->where('id', $row->training_id)
                    ->update([$column => $row->value]));

            Schema::dropIfExists($table);
        }
    }
};
