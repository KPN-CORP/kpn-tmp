<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy roles were scoped to a set of business units / companies / locations
 * (stored as JSON arrays). Preserve that so a role can be limited to the
 * employees it is allowed to see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('business_unit')->nullable()->after('name');
            $table->json('company')->nullable()->after('business_unit');
            $table->json('location')->nullable()->after('company');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['business_unit', 'company', 'location']);
        });
    }
};
