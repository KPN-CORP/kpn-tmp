<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Local/QA only: log in as any employee without SSO. Gated behind a shared
 * secret (services.dev_login.key). Step 1 verifies the key; step 2 picks an
 * employee to impersonate. Disabled entirely when no key is configured.
 */
class DevLoginController extends Controller
{
    private function enabled(): bool
    {
        return ! empty(config('services.dev_login.key'));
    }

    public function create(): Response|RedirectResponse
    {
        if (! $this->enabled()) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/DevLogin');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->enabled(), 404);

        $validated = $request->validate([
            'access_key' => ['required', 'string'],
        ]);

        if (! hash_equals((string) config('services.dev_login.key'), $validated['access_key'])) {
            throw ValidationException::withMessages([
                'access_key' => 'Invalid development login key.',
            ]);
        }

        $request->session()->put('dev_login_verified', true);

        return redirect()->route('dev.login.employees');
    }

    public function employees(Request $request): Response|RedirectResponse
    {
        if (! $this->verified($request)) {
            return redirect()->route('dev.login');
        }

        return Inertia::render('Auth/EmployeeLogin');
    }

    public function search(Request $request): JsonResponse
    {
        abort_unless($this->verified($request), 403);

        $term = trim((string) $request->input('q', ''));

        $employees = Employee::query()
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($sub) use ($term) {
                    $sub->where('employee_id', 'like', "%{$term}%")
                        ->orWhere('fullname', 'like', "%{$term}%")
                        ->orWhere('designation_name', 'like', "%{$term}%")
                        ->orWhere('group_company', 'like', "%{$term}%");
                });
            })
            ->orderBy('fullname')
            ->limit(30)
            ->get(['employee_id', 'fullname', 'designation_name', 'group_company']);

        return response()->json($employees);
    }

    public function impersonate(Request $request): RedirectResponse
    {
        abort_unless($this->verified($request), 403);

        $validated = $request->validate([
            'employee_id' => ['required', 'string'],
        ]);

        $employee = Employee::where('employee_id', $validated['employee_id'])->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => 'Employee not found.',
            ]);
        }

        $user = User::firstOrCreate(
            ['employee_id' => $employee->employee_id],
            [
                'name' => $employee->fullname,
                'email' => $employee->employee_id.'@dev.local',
                'password' => Hash::make(str()->random(40)),
            ],
        );

        Auth::login($user);
        $request->session()->forget('dev_login_verified');
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function verified(Request $request): bool
    {
        return $this->enabled() && $request->session()->get('dev_login_verified') === true;
    }
}
