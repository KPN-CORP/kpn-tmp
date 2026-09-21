<?php

namespace App\Support;

/**
 * Where a signed-in user lands.
 *
 * Four paths reach the app — password login, dev-login impersonation, SSO, and
 * `/` — and they all have to agree on the destination, so the answer lives in
 * one place rather than being repeated as a route name in each of them.
 *
 * The Task Box is that destination: what is waiting on the person is the first
 * thing they should see. It holds every request they sit on the approval chain
 * of, which is empty for most people most of the time — an empty desk is a
 * legitimate landing, and its own answer to "is there anything for me?".
 */
final class Landing
{
    /** The route name every successful sign-in redirects to. */
    public const ROUTE = 'approvals.inbox';

    public static function url(): string
    {
        return route(self::ROUTE);
    }
}
