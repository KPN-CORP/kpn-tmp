<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A master implementation now scopes to *any number* of grades instead of one,
 * mirroring how a development program stores its grades. The single
 * `competency_implementations.grade` column becomes `implementation_grades`
 * rows; the existing value is carried over as the first (only) row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('implementation_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('implementation_id')
                ->constrained('competency_implementations')
                ->cascadeOnDelete();
            $table->string('grade');
            $table->unique(['implementation_id', 'grade'], 'implementation_grade_unique');
        });

        DB::table('competency_implementations')
            ->whereNotNull('grade')
            ->where('grade', '!=', '')
            ->orderBy('id')
            ->select('id', 'grade')
            ->chunk(500, function ($rows) {
                DB::table('implementation_grades')->insert(
                    $rows->map(fn ($row) => [
                        'implementation_id' => $row->id,
                        'grade' => $row->grade,
                    ])->all()
                );
            });

        Schema::table('competency_implementations', function (Blueprint $table) {
            $table->dropColumn('grade');
        });
    }

    public function down(): void
    {
        Schema::table('competency_implementations', function (Blueprint $table) {
            $table->string('grade')->nullable()->after('competency_id');
        });

        // Only one grade fits back into the column; keep the lowest-id row.
        $first = DB::table('implementation_grades')
            ->select('implementation_id', DB::raw('MIN(id) as id'))
            ->groupBy('implementation_id');

        DB::table('implementation_grades')
            ->joinSub($first, 'f', 'f.id', '=', 'implementation_grades.id')
            ->orderBy('implementation_grades.id')
            ->select('implementation_grades.implementation_id', 'implementation_grades.grade')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('competency_implementations')
                        ->where('id', $row->implementation_id)
                        ->update(['grade' => $row->grade]);
                }
            });

        Schema::dropIfExists('implementation_grades');
    }
};
