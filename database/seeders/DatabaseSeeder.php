<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reference / config data first (permissions before roles).
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            DevelopmentModelSeeder::class,
            MatrixGradeConfigSeeder::class,
            ApprovalFlowSeeder::class,
        ]);

        // A known account for signing in during development.
        // Idempotent so re-seeding never throws on the unique email.
        $admin = User::updateOrCreate(
            ['email' => 'admin@kpn.co.id'],
            [
                'name' => 'KPN Admin',
                'password' => Hash::make('password'),
            ],
        );

        $admin->syncRoles(['Superadmin']);

        // Sample dataset for local testing: corporate employees (kpncorp) plus
        // app-owned talent data. Both skip gracefully if kpncorp is unreachable.
        $this->call([
            EmployeeSampleSeeder::class,
            SampleDataSeeder::class,
        ]);
    }
}
