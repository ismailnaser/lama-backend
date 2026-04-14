<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\Cors;
use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\RequireAdmin;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(Cors::class);
        $middleware->alias([
            'auth.token' => ApiTokenAuth::class,
            'admin' => RequireAdmin::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'api/*', // استثناء مسارات الـ API من حماية CSRF
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
