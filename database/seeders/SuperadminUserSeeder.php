<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Grants the Superadmin role to the named accounts (by corporate employee_id).
 *
 * This seeder does NOT create users — the accounts are expected to already
 * exist (e.g. provisioned via SSO on first sign-in). Any employee_id without a
 * matching user row is skipped.
 */
class SuperadminUserSeeder extends Seeder
{
    /** Corporate employee_ids that should be Superadmin. */
    private const SUPERADMIN_EMPLOYEE_IDS = [
        '01124040023',
        '01124090037',
        '01126010024',
    ];

    public function run(): void
    {
        foreach (self::SUPERADMIN_EMPLOYEE_IDS as $employeeId) {
            $user = User::where('employee_id', $employeeId)->first();

            if (! $user) {
                $this->command?->warn("No user for employee {$employeeId} — skipped.");

                continue;
            }

            // assignRole (additive + idempotent) rather than syncRoles so a user
            // keeps any other roles they already hold — a user may have many.
            $user->assignRole('Superadmin');

            $this->command?->info("Superadmin: {$user->name} <{$user->email}> ({$employeeId})");
        }
    }
}
