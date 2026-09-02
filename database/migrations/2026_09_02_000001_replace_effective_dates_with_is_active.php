<?php

use App\Services\MasterStatusAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the effective period on the dated IDP masters with a plain
 * active/inactive flag.
 *
 * The period expressed *when* a master applied; the client only ever needs to
 * say whether it applies at all, and wants the change itself attributed — which
 * a date window cannot record. The flag carries that meaning directly, and the
 * who/when lives in the append-only audit log written by
 * {@see MasterStatusAudit} (deliberately outside the database, so
 * toggling never adds write volume to it).
 *
 * Backfill: a row effective today becomes active, anything else inactive, so
 * the visible behaviour of every existing row carries over unchanged.
 */
return new class extends Migration
{
    /**
     * The masters that carried an effective period.
     *
     * @var list<string>
     */
    private array $tables = ['competencies', 'proficiency_levels', 'review_tools'];

    public function up(): void
    {
        $today = now()->toDateString();

        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('is_active')->default(true)->after('name_id');
            });

            // Effective today => active. A row with no dates was always
            // effective, so it stays active.
            DB::table($table)
                ->where(function ($q) use ($today) {
                    $q->whereNotNull('effective_start_date')->whereDate('effective_start_date', '>', $today);
                })
                ->orWhere(function ($q) use ($today) {
                    $q->whereNotNull('effective_end_date')->whereDate('effective_end_date', '<', $today);
                })
                ->update(['is_active' => false]);

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['effective_start_date', 'effective_end_date']);
            });
        }
    }

    public function down(): void
    {
        // The original dates cannot be recovered from a boolean. What is
        // restored is the *meaning*: an active row gets an open window (which
        // is how every row behaved before any dates were entered), and an
        // inactive one is given a window that closed yesterday, so it reads as
        // expired exactly as the flag said it was off.
        $yesterday = now()->subDay()->toDateString();

        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->date('effective_start_date')->nullable()->after('name_id');
                $blueprint->date('effective_end_date')->nullable()->after('effective_start_date');
            });

            DB::table($table)
                ->where('is_active', false)
                ->update(['effective_end_date' => $yesterday]);

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('is_active');
            });
        }
    }
};
