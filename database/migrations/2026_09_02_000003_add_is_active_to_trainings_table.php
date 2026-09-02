<?php

use App\Services\MasterStatusAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A master training can be switched off without being deleted, matching the
 * flag the other IDP masters and master implementations already carry.
 *
 * Deactivating keeps everything referencing the training: a development program
 * that took its name from one still holds that name (the name is copied onto
 * the program, not read through the relation), and the delete guard still
 * blocks removing a training a program was named from. Only the pickers narrow.
 *
 * Who flipped the flag is recorded outside the database by
 * {@see MasterStatusAudit}, through the shared master endpoints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('name_id');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
