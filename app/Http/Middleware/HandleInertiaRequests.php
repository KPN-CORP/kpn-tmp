<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user,
                // The corporate employee record (kpncorp). Resolved lazily and
                // guarded so a missing/unreachable kpncorp connection can never
                // break the app shell.
                'employee' => fn () => $this->resolveEmployee($user),
            ],

            // Drives permission-gated menu items in useNavigation().
            'permissions' => $user && method_exists($user, 'getAllPermissions')
                ? $user->getAllPermissions()->pluck('name')->values()
                : [],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Best-effort lookup of the signed-in user's corporate employee record.
     * Returns null (never throws) if there is no user, no employee_id, or the
     * kpncorp connection is unavailable.
     */
    private function resolveEmployee($user): ?array
    {
        if (! $user || empty($user->employee_id)) {
            return null;
        }

        try {
            $employee = $user->employee()->first();
        } catch (\Throwable $e) {
            return null;
        }

        if (! $employee) {
            return null;
        }

        return [
            'employee_id' => $employee->employee_id,
            'fullname' => $employee->fullname,
            'email' => $employee->email,
            'designation_name' => $employee->designation_name,
            'group_company' => $employee->group_company,
            'company_name' => $employee->company_name,
            'unit' => $employee->unit,
        ];
    }
}
