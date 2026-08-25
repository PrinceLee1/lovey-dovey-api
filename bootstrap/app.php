<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);

        // Applies the 'api' rate limiter (see AppServiceProvider) to every
        // api.php route — general abuse/DoS protection on top of the
        // stricter 'auth' limiter applied directly to the login/register
        // endpoints below.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
