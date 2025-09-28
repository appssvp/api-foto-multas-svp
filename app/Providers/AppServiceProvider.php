<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configurar rate limiting para diferentes tipos de endpoints
     */
    protected function configureRateLimiting(): void
    {
        // LOGIN: Usar variable de entorno
        RateLimiter::for('login', function (Request $request) {
            $maxAttempts = config('fotomultas.rate_limits.login_attempts', 5);
            
            return [
                // Por IP: máximo definido en .env
                Limit::perMinute($maxAttempts)->by($request->ip()),
                // Por email: máximo 3 intentos por minuto por email específico
                Limit::perMinute(3)->by($request->input('email'))->response(function () {
                    return response()->json([
                        'message' => 'Demasiados intentos de login. Intenta en 1 minuto.',
                        'retry_after' => 60
                    ], 429);
                })
            ];
        });

        // API GENERAL: Usar variable de entorno
        RateLimiter::for('api', function (Request $request) {
            $maxRequests = config('fotomultas.rate_limits.api_requests', 100);
            
            return $request->user()
                ? [
                    // Usuarios autenticados: definido en .env
                    Limit::perMinute($maxRequests)->by($request->user()->id),
                    // Fallback por IP: 60% del limite principal
                    Limit::perMinute(intval($maxRequests * 0.6))->by($request->ip())
                ]
                : [
                    // No autenticados: 20% del limite principal
                    Limit::perMinute(intval($maxRequests * 0.2))->by($request->ip())
                ];
        });

        // IMÁGENES: Usar variable de entorno
        RateLimiter::for('images', function (Request $request) {
            $maxImages = config('fotomultas.rate_limits.image_requests', 200);
            
            return $request->user()
                ? [
                    // Usuarios autenticados: definido en .env
                    Limit::perMinute($maxImages)->by($request->user()->id),
                    // Burst protection: 150% del limite para burst cortos
                    Limit::perMinute(intval($maxImages * 1.5))->by($request->user()->id)->response(function () {
                        return response()->json([
                            'message' => 'Límite de imágenes excedido. Reduce la velocidad de requests.',
                            'retry_after' => 10
                        ], 429);
                    })
                ]
                : [
                    // No autenticados: sin acceso a imágenes
                    Limit::none()
                ];
        });

        // ADMIN: Muy restrictivo para operaciones administrativas
        RateLimiter::for('admin', function (Request $request) {
            return [
                // Solo 30 requests por minuto para operaciones admin
                Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()),
                // Máximo 10 requests en 10 segundos para prevenir burst
                Limit::perMinute(60)->by($request->user()?->id ?: $request->ip())
            ];
        });
    }
}