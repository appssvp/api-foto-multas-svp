<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\SecurityMiddleware::class,
        ]);
        
        // Registrar alias de middlewares personalizados
        $middleware->alias([
            'api.key' => \App\Http\Middleware\ApiKeyAuth::class,
            'security' => \App\Http\Middleware\SecurityMiddleware::class, 
        ]);
        
        // Excluir rutas API del middleware CSRF
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
        
        // SEGURIDAD: Agregar headers de seguridad
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();