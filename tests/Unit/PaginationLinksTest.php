<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

/**
 * Behind the TLS-terminating proxy a request can reach PHP as http://. Page
 * links built from it were absolute http URLs, which the browser blocks on an
 * https page (mixed content), so "next page" failed with a network error.
 * They must be relative.
 */
class PaginationLinksTest extends TestCase
{
    public function test_page_links_are_relative_whatever_scheme_the_request_arrived_on(): void
    {
        $this->app->instance('request', Request::create(
            'http://talent-management-staging.hcis.live/idp?direction=asc&per_page=10&sort=job_level',
        ));

        // What `->paginate()` (and the Task Box) pass as the path.
        $paginator = new LengthAwarePaginator(range(1, 10), 40, 10, 1, ['path' => Paginator::resolveCurrentPath()]);
        $paginator->appends(['sort' => 'job_level']);

        $this->assertSame('/idp?sort=job_level&page=2', $paginator->url(2));
        $this->assertSame('/idp?sort=job_level&page=2', $paginator->nextPageUrl());

        foreach ($paginator->linkCollection() as $link) {
            if ($link['url'] !== null) {
                $this->assertStringStartsWith('/idp?', $link['url']);
            }
        }
    }

    public function test_the_resolver_keeps_a_subdirectory_install(): void
    {
        $request = Request::create('https://example.test/app/idp', 'GET', [], [], [], [
            'SCRIPT_NAME' => '/app/index.php',
            'SCRIPT_FILENAME' => '/var/www/app/index.php',
        ]);
        $this->app->instance('request', $request);

        $this->assertSame('/app/idp', Paginator::resolveCurrentPath());
    }
}
