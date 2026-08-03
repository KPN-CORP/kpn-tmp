<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie's permissions table stores only name + guard. We keep the extra
 * presentation metadata from the legacy app (label/group/section) so the admin
 * Roles UI can group permissions the same way facecard did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('label')->nullable()->after('name');
            $table->string('group')->nullable()->after('label');
            $table->string('section')->nullable()->after('group');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['label', 'group', 'section']);
        });
    }
};
