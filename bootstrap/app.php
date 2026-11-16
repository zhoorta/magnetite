<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\PublicSite;
use App\Http\Middleware\RedirectToSetup;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['abilities' => CheckAbilities::class, 'public-site' => PublicSite::class, 'admin' => EnsureAdmin::class]);
        $middleware->appendToGroup('web', RedirectToSetup::class);
        $middleware->redirectUsersTo(fn () => route('library'));

        // Behind a reverse proxy the real address and scheme come from forwarded headers. Trusting them from anywhere
        // would let a visitor fake an address and dodge the sign-in throttle, so the proxy addresses are listed.
        $proxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));
        $proxies && $middleware->trustProxies(at: $proxies);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
