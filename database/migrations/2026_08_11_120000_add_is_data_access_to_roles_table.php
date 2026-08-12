<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Classifies a role as either a "basic" permission role (functional menus /
 * actions — the Permissions tab) or a "data access" role (auto-applied to the
 * users in its Access Scope, granting facecard/IDP view/download — the Data
 * Access tab). Data-access roles are never hand-assigned; they apply to whoever
 * falls in their scope, so they carry no member list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_data_access')->default(false)->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_data_access');
        });
    }
};
