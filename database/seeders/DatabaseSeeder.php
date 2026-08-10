<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Only the authorization reference data (permissions, roles) and the named
     * Superadmin accounts are seeded. Sample/test data seeders were removed.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SuperadminUserSeeder::class,
        ]);
    }
}
