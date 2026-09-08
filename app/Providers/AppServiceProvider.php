<?php

namespace App\Providers;

use App\Services\CorporateScopeService;
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
    }
}
