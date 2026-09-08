<?php

namespace App\Providers;

use App\Services\CorporateScopeService;
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
        //
    }
}
