<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SSO entry point. An external portal redirects here with an encrypted `data`
 * query param carrying the user's email + a Darwinbox token. We decrypt it,
 * verify the token against the SSO service, then log the matching local user in.
 */
class SsoController extends Controller
{
    public function dbauth(Request $request): RedirectResponse
    {
        $payload = $this->decryptPayload((string) $request->query('data'));

        if (! $payload || empty($payload['email']) || empty($payload['token'])) {
            return $this->failure('Malformed SSO payload.');
        }

        if (! $this->tokenIsValid($payload['token'])) {
            return $this->failure('SSO token rejected.');
        }

        $user = User::where('email', $payload['email'])->first();

        if (! $user) {
            return $this->failure("No local account for {$payload['email']}.");
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * base64 -> XOR(xor_key) -> base64 -> JSON.
     */
    private function decryptPayload(string $data): ?array
    {
        if ($data === '') {
            return null;
        }

        $xored = $this->xorCipher(base64_decode($data), (string) config('services.sso.xor_key'));
        $json = base64_decode($xored);

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function tokenIsValid(string $token): bool
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['Authorization' => (string) config('services.sso.authorization')])
                ->post((string) config('services.sso.check_token_url'), [
                    'api_key' => (string) config('services.sso.api_key'),
                    'token' => $token,
                ]);
        } catch (\Throwable $e) {
            Log::warning('SSO token check failed', ['message' => $e->getMessage()]);

            return false;
        }

        return (int) $response->json('status') === 1;
    }

    private function failure(string $reason): RedirectResponse
    {
        Log::warning('SSO login failed', ['reason' => $reason]);

        return redirect()->away((string) config('services.sso.failure_redirect'))
            ->with('error', 'Login failed. Please contact your administrator.');
    }

    /**
     * Symmetric XOR — same routine encrypts and decrypts.
     */
    private function xorCipher(string $data, string $key): string
    {
        if ($key === '') {
            return $data;
        }

        $keyLength = strlen($key);
        $out = '';

        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $out .= $data[$i] ^ $key[$i % $keyLength];
        }

        return $out;
    }
}
