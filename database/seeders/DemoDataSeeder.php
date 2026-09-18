<?php

namespace Database\Seeders;

use Database\Seeders\Demo\ApprovalDemoSeeder;
use Database\Seeders\Demo\IdpMasterDemoSeeder;
use Database\Seeders\Demo\MatrixGradeDemoSeeder;
use Database\Seeders\Demo\OperationalDemoSeeder;
use Database\Seeders\Demo\TalentDemoSeeder;
use Illuminate\Database\Seeder;

/**
 * Fills every screen in the app with realistic demo data.
 *
 * Deliberately NOT wired into {@see DatabaseSeeder}: that one seeds only the
 * authorization reference data a real environment needs. Run this one on
 * purpose, and only where sample data is wanted:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * It is additive and idempotent -- rows are matched on their natural key and
 * updated, child rows that already exist are never replaced, and nothing is
 * ever deleted. Re-running it is safe.
 *
 * It does NOT create employees or user accounts: both are corporate data read
 * from the kpncorp connection, which the app treats as the source of truth.
 * Parts that depend on kpncorp skip themselves with a warning when it is
 * unreachable.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Matrix grade configuration');
        $this->call(MatrixGradeDemoSeeder::class);

        $this->command?->info('IDP master data');
        $this->call(IdpMasterDemoSeeder::class);

        $this->command?->info('Talent data (assessments, succession, nine-box, IDP plans)');
        $this->call(TalentDemoSeeder::class);

        $this->command?->info('Approval layers and workflows');
        $this->call(ApprovalDemoSeeder::class);

        $this->command?->info('Import logs and user guides');
        $this->call(OperationalDemoSeeder::class);

        $this->command?->info('Demo data complete.');
    }
}
