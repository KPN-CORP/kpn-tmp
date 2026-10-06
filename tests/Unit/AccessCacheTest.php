<?php

namespace Tests\Unit;

use App\Support\AccessCache;
use Tests\TestCase;

/** The per-user access facts are cached, and one flush invalidates all of them. */
class AccessCacheTest extends TestCase
{
    public function test_a_value_is_computed_once_until_flushed(): void
    {
        $calls = 0;
        $compute = function () use (&$calls) {
            return ++$calls;
        };

        $this->assertSame(1, AccessCache::remember('permissions:1', $compute));
        $this->assertSame(1, AccessCache::remember('permissions:1', $compute));

        AccessCache::flush();

        $this->assertSame(2, AccessCache::remember('permissions:1', $compute));
    }

    public function test_a_flush_invalidates_every_key(): void
    {
        AccessCache::remember('a', fn () => 'old-a');
        AccessCache::remember('b', fn () => 'old-b');

        AccessCache::flush();

        $this->assertSame('new-a', AccessCache::remember('a', fn () => 'new-a'));
        $this->assertSame('new-b', AccessCache::remember('b', fn () => 'new-b'));
    }
}
