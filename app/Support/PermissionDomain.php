<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * This app's row in the shared role/permission database's `domains` table.
 *
 * Roles and permissions live in one database shared by every HCIS app
 * (connection `sys_perm`); each app owns the rows carrying its `domain_id`.
 * The domain is named by config('services.sys_perm.domain') (DOMAIN_SYS_PERM).
 */
class PermissionDomain
{
    public const CONNECTION = 'sys_perm';

    private static ?int $id = null;

    /**
     * The domain id, or null when the domain is not configured / not registered
     * (or is switched off). Callers decide whether that is fatal.
     */
    public static function id(): ?int
    {
        if (self::$id !== null) {
            return self::$id;
        }

        $name = config('services.sys_perm.domain');
        if (! $name) {
            return null;
        }

        // Cached because every page reads it (the permission map is shared to
        // the frontend). A missing domain is NOT cached, so registering it
        // takes effect without a cache flush.
        $id = Cache::get(self::cacheKey($name));
        if ($id === null) {
            $id = DB::connection(self::CONNECTION)->table('domains')
                ->where('name', $name)->where('is_active', true)->value('id');
            if ($id !== null) {
                Cache::put(self::cacheKey($name), (int) $id, now()->addDay());
            }
        }

        return self::$id = $id === null ? null : (int) $id;
    }

    /** The domain id, failing loudly when it cannot be resolved (used on writes). */
    public static function idOrFail(): int
    {
        return self::id() ?? throw new RuntimeException(sprintf(
            'Permission domain "%s" is not registered (or inactive) in the %s.domains table. Set DOMAIN_SYS_PERM and run database/sql/sys_permission_setup.sql.',
            config('services.sys_perm.domain'),
            self::CONNECTION,
        ));
    }

    public static function forget(): void
    {
        if ($name = config('services.sys_perm.domain')) {
            Cache::forget(self::cacheKey($name));
        }
        self::$id = null;
    }

    private static function cacheKey(string $name): string
    {
        return 'sys_perm.domain_id.'.$name;
    }
}
