<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the named Superadmin accounts. Each is keyed on a corporate
 * employee_id; the account details (name, email) are resolved from the
 * read-only kpncorp `employees` master, so the app-owned `users` row mirrors
 * the corporate record. Sign-in is via SSO (matched on employee_id) — the
 * password is randomised and never used for these accounts.
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
            $employee = $this->resolveEmployee($employeeId);

            if (! $employee) {
                $this->command?->warn(
                    "Employee {$employeeId} not found in kpncorp — creating Superadmin with fallback details."
                );
            }

            $email = $employee?->email
                ?: $employee?->personal_email
                ?: $employeeId.'@kpn.co.id';

            // Keyed on employee_id. Don't clobber an existing password on reseed.
            $user = User::firstOrNew(['employee_id' => $employeeId]);
            $user->name = $employee?->fullname ?: ($user->name ?: $employeeId);
            $user->email = $email;
            if (! $user->exists) {
                $user->password = Hash::make(Str::random(40));
            }
            $user->save();

            $user->syncRoles(['Superadmin']);

            $this->command?->info("Superadmin: {$user->name} <{$user->email}> ({$employeeId})");
        }
    }

    /**
     * Look up the corporate employee on the kpncorp connection. Returns null if
     * the connection is unreachable or the id is unknown.
     */
    private function resolveEmployee(string $employeeId): ?Employee
    {
        try {
            return Employee::where('employee_id', $employeeId)->first();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
