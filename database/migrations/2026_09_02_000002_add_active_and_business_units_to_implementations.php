<?php

use App\Services\MasterStatusAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes to Master Implementation.
 *
 * 1. `is_active` — an implementation can be switched off without deleting the
 *    mapping, matching the flag the IDP masters gained. Who flipped it is
 *    recorded outside the database by {@see MasterStatusAudit}.
 *
 * 2. A mapping now covers *any number* of business units instead of one, so the
 *    same competency scope no longer needs a duplicate row per unit. The single
 *    `business_unit` column becomes `implementation_business_units` rows, the
 *    same shape `implementation_grades` and `training_business_units` already
 *    use for raw corporate strings. The existing value is carried over as the
 *    first (only) row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competency_implementations', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('competency_id');
        });

        Schema::create('implementation_business_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('implementation_id')
                ->constrained('competency_implementations')
                ->cascadeOnDelete();
            $table->string('business_unit');
            $table->unique(['implementation_id', 'business_unit'], 'implementation_business_unit_unique');
        });

        DB::table('competency_implementations')
            ->whereNotNull('business_unit')
            ->where('business_unit', '!=', '')
            ->orderBy('id')
            ->select('id', 'business_unit')
            ->chunk(500, function ($rows) {
                DB::table('implementation_business_units')->insert(
                    $rows->map(fn ($row) => [
                        'implementation_id' => $row->id,
                        'business_unit' => $row->business_unit,
                    ])->all()
                );
            });

        Schema::table('competency_implementations', function (Blueprint $table) {
            $table->dropColumn('business_unit');
        });
    }

    public function down(): void
    {
        Schema::table('competency_implementations', function (Blueprint $table) {
            $table->string('business_unit')->nullable()->after('competency_id');
        });

        // Only one unit fits back into the column; keep the lowest-id row.
        $first = DB::table('implementation_business_units')
            ->select('implementation_id', DB::raw('MIN(id) as id'))
            ->groupBy('implementation_id');

        DB::table('implementation_business_units')
            ->joinSub($first, 'f', 'f.id', '=', 'implementation_business_units.id')
            ->orderBy('implementation_business_units.id')
            ->select(
                'implementation_business_units.implementation_id',
                'implementation_business_units.business_unit'
            )
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('competency_implementations')
                        ->where('id', $row->implementation_id)
                        ->update(['business_unit' => $row->business_unit]);
                }
            });

        Schema::dropIfExists('implementation_business_units');

        Schema::table('competency_implementations', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
