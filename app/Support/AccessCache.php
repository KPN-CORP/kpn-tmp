<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache for "who may see what" facts that are read on every page
 * but change rarely: a user's permission names, the data-access role list, and
 * whether a user has a team. Most of these come from the shared permission DB,
 * where each round trip costs tens of milliseconds.
 *
 * Every key carries a version; {@see flush()} bumps it, so a save on the Roles
 * or Approval Layer screen invalidates everything at once without having to
 * know which users it touched. The TTL bounds staleness for changes made
 * outside this app (another app editing a shared role, the corporate master
 * re-assigning a manager).
 */
final class AccessCache
{
    private const VERSION_KEY = 'access-cache:version';

    private const TTL_SECONDS = 300;

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $key, Closure $compute): mixed
    {
        return Cache::remember(
            'access-cache:v'.self::version().':'.$key,
            self::TTL_SECONDS,
            $compute,
        );
    }

    /** Invalidate every cached access fact (call after a role or chain change). */
    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }
}
