<?php

namespace App\Http\Middleware;

use App\Models\ApprovalNotification;
use App\Services\IdpApprovalService;
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

            // In-app approval notifications for the signed-in user.
            'notifications' => fn () => $this->notifications($user),
            // How many IDP items are awaiting this user's approval decision.
            'pendingApprovals' => fn () => $this->pendingApprovals($user),

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Recent approval notifications + unread count for the shell's bell.
     * Guarded so a missing table / connection never breaks the app shell.
     *
     * @return array{items: array<int, array<string, mixed>>, unread: int}
     */
    private function notifications($user): array
    {
        if (! $user) {
            return ['items' => [], 'unread' => 0];
        }

        try {
            $items = ApprovalNotification::where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(15)
                ->get()
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'type' => $n->type,
                    'title' => $n->title,
                    'message' => $n->message,
                    'link' => $n->link,
                    'read_at' => $n->read_at?->toDateTimeString(),
                    'created_at' => $n->created_at?->toDateTimeString(),
                ])
                ->all();

            $unread = ApprovalNotification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count();

            return ['items' => $items, 'unread' => $unread];
        } catch (\Throwable) {
            return ['items' => [], 'unread' => 0];
        }
    }

    private function pendingApprovals($user): int
    {
        if (! $user) {
            return 0;
        }

        try {
            return app(IdpApprovalService::class)->pendingCountFor($user);
        } catch (\Throwable) {
            return 0;
        }
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
