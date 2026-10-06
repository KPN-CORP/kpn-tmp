<?php

namespace App\Providers;

use App\Services\CorporateScopeService;
use App\Services\EmployeeScopeService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The corporate org-scope option lists are read by several collaborators
        // within one request — a controller shaping props and a rule class
        // checking a submitted scope against them. One instance per request is
        // what makes its memoization worth anything.
        $this->app->singleton(CorporateScopeService::class);

        // Scoped, not singleton: it memoizes per-user answers, which must not
        // outlive the request (or queued job) that computed them.
        $this->app->scoped(EmployeeScopeService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Belt and braces alongside the trusted proxies in bootstrap/app.php:
        // if the proxy is misconfigured or strips X-Forwarded-Proto, generated
        // URLs would silently fall back to http on an https deployment and
        // every redirect after a save would be blocked by the browser as an
        // insecure redirect. APP_URL is the deployment's own statement of what
        // scheme it is served on, so honour it. Read from config, not env(),
        // so it survives `config:cache`.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Page links are RELATIVE (path + query, no scheme or host). Laravel
        // builds them from `$request->url()`, which reflects how the request
        // reached PHP — `http://` behind a TLS proxy that does not forward the
        // scheme — and forceScheme() above does not reach it. An absolute
        // http link on an https page is blocked by the browser as mixed
        // content, so every "next page" failed with a network error. Relative
        // links take the page's own scheme and host wherever it is served.
        Paginator::currentPathResolver(
            fn () => $this->app['request']->getBaseUrl().$this->app['request']->getPathInfo(),
        );
    }
}
