<?php

namespace App\Http\Middleware;

use App\Models\ApprovalNotification;
use App\Models\User;
use App\Services\ApprovalChainService;
use App\Services\EmployeeScopeService;
use App\Services\IdpApprovalService;
use App\Support\AccessCache;
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
                // Only what the shell shows. The whole model would also carry
                // `token` / `email_log` and whatever relations happen to be
                // loaded (roles with all their permissions — ~14 KB).
                'user' => $user ? [
                    'id' => $user->getKey(),
                    'employee_id' => $user->employee_id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
                // The corporate employee record (kpncorp). Resolved lazily and
                // guarded so a missing/unreachable kpncorp connection can never
                // break the app shell.
                'employee' => fn () => $this->resolveEmployee($user),
                // Whether they have subordinates at all - what decides if the
                // Team Development Plan menu item is worth showing.
                'has_team' => fn () => $this->hasTeam($user),
            ],

            // Drives permission-gated menu items in useNavigation(). Lazy, so a
            // partial reload skips it, and cached: it is several round trips to
            // the shared permission DB. Routes still check permissions live.
            'permissions' => fn () => $this->permissionNames($user),

            // In-app approval notifications for the signed-in user.
            'notifications' => fn () => $this->notifications($user),
            // How many IDP items need this user right now: requests they can
            // decide, plus their own rejected plan / results to revise. Their Task Box
            // also lists the requests still with an earlier layer; those are not
            // theirs to act on, so the badge does not count them.
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

    /** @return list<string> */
    private function permissionNames(?User $user): array
    {
        if (! $user || ! method_exists($user, 'getAllPermissions')) {
            return [];
        }

        return AccessCache::remember(
            'permissions:'.$user->getKey(),
            fn () => $user->getAllPermissions()->pluck('name')->values()->all(),
        );
    }

    private function pendingApprovals($user): int
    {
        if (! $user) {
            return 0;
        }

        try {
            $approvals = app(IdpApprovalService::class);

            return $approvals->actionableCountFor($user) + $approvals->revisionCountFor($user);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Whether this user has a team, for the Team Development Plan menu item.
     *
     * The rule the client asked for is the APPROVAL LAYER: someone is a
     * manager here when at least one employee's effective approval chain names
     * them. That is what `ApprovalChainService::hasSubordinates()` answers, and
     * it is the whole rule for an ordinary employee.
     *
     * A role-holder gets a second chance: an HC admin or a Superadmin is
     * usually nobody's approver, yet their scope covers hundreds of people and
     * hiding their team list would be a plain regression. The clause is skipped
     * for a user with no roles - where it could never be true anyway - which
     * also keeps the extra query off the common path.
     */
    private function hasTeam(?User $user): bool
    {
        if (! $user || empty($user->employee_id)) {
            return false;
        }

        // Read on every full page load and costs several cross-database
        // queries; it changes only when a chain, a role or the corporate
        // manager changes. AccessCache is flushed on the first two.
        return AccessCache::remember('has-team:'.$user->getKey(), fn () => $this->computeHasTeam($user));
    }

    private function computeHasTeam(User $user): bool
    {

        if (app(ApprovalChainService::class)->hasSubordinates((string) $user->employee_id)) {
            return true;
        }

        if (! method_exists($user, 'roles') || $user->roles->isEmpty()) {
            return false;
        }

        try {
            return app(EmployeeScopeService::class)
                ->accessibleQuery($user, 'ic_view_idp', 'pm_view_idp')
                ->where('employee_id', '!=', $user->employee_id)
                ->exists();
        } catch (\Throwable) {
            // kpncorp unreachable - the shell must still render.
            return false;
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
