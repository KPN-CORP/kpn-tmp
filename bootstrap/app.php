<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The app is served behind a TLS-terminating reverse proxy (staging and
        // production both), so without this Laravel sees a plain http request
        // and generates http:// URLs for an https:// page. That breaks every
        // absolute URL it builds — most visibly a redirect after a POST/PUT:
        // the browser refuses to follow an https -> http redirect from an XHR,
        // so an Inertia save fails with "Network error" even though the write
        // succeeded. It also makes $request->ip() the proxy's address and
        // isSecure() false, which the `secure` cookie flag keys off.
        //
        // '*' trusts whatever proxy forwarded the request, which is right while
        // the app is only reachable *through* the balancer. Narrow it to the
        // balancer's addresses if the origin ever becomes directly reachable.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Spatie permission/role middleware aliases, used to gate routes.
        $middleware->alias([
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Guests hitting an `auth`-protected page land on the login screen
        // rather than a generic 403.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
