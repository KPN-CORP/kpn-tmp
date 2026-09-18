<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `import_logs.user_id` was created with `->constrained()`, pointing at a
 * `users` table in THIS database. Users live on the kpncorp connection
 * ({@see User}), so the app-side `users` table is empty and the
 * constraint rejects every row the Import Center writes -- it stores
 * `$request->user()->id`, a kpncorp id, which can never satisfy it.
 *
 * The column stays (it is read back through the `user()` relation, which
 * resolves on kpncorp); only the cross-database constraint goes. A foreign key
 * cannot span two databases, so there is nothing to point it at instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
