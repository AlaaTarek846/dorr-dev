<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsurePhoneVerified;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\ResolveCountryContext;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'guest' => RedirectIfAuthenticated::class,
            'locale' => SetLocale::class,
            'remember-locale' => \App\Http\Middleware\RememberLocale::class,
            'country' => ResolveCountryContext::class,
            'ensure-phone-verified' => EnsurePhoneVerified::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            $renderer = new ApiExceptionRenderer;

            if (! $renderer->shouldRender($request)) {
                return null;
            }

            return $renderer->render($e, $request);
        });
    })->create();
