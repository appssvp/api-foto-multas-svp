<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;

class ApiKeyRateLimiter
{
    /**
     * Límites por defecto si la API Key no tiene configuración específica
     */
    private const DEFAULT_MAX_ATTEMPTS = 100; // requests por minuto
    private const DEFAULT_DECAY_MINUTES = 1;

    /**
     * Límites específicos por nombre de API Key
     */
    private array $customLimits = [
        'Producción' => [
            'max_attempts' => 100,
            'decay_minutes' => 1,
        ],
        'Monitoreo' => [
            'max_attempts' => 500,
            'decay_minutes' => 1,
        ],
        'Desarrollo' => [
            'max_attempts' => 50,
            'decay_minutes' => 1,
        ],
        'Prueba Local' => [
            'max_attempts' => 30,
            'decay_minutes' => 1,
        ],
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Obtener API Key del request (ya validada por ApiKeyAuth middleware)
        $apiKey = $request->attributes->get('api_key');

        if (!$apiKey) {
            // Si no hay API Key, aplicar rate limit por IP (fallback)
            return $this->rateLimitByIp($request, $next);
        }

        // Obtener límites personalizados o usar defaults
        $limits = $this->customLimits[$apiKey->name] ?? [
            'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
            'decay_minutes' => self::DEFAULT_DECAY_MINUTES,
        ];

        $maxAttempts = $limits['max_attempts'];
        $decayMinutes = $limits['decay_minutes'];

        // Crear key única para esta API Key
        $rateLimitKey = 'api-key:' . $apiKey->id;

        // Ejecutar rate limiter
        $executed = RateLimiter::attempt(
            $rateLimitKey,
            $maxAttempts,
            function () use ($request, $next) {
                return $next($request);
            },
            $decayMinutes * 60
        );

        if (!$executed) {
            // Se excedió el rate limit
            $availableAt = RateLimiter::availableIn($rateLimitKey);

            Log::channel('fotomultas')->warning('Rate limit exceeded for API Key', [
                'api_key_name' => $apiKey->name,
                'api_key_id' => $apiKey->id,
                'user_id' => $apiKey->user_id,
                'ip' => $request->ip(),
                'url' => $request->fullUrl(),
                'max_attempts' => $maxAttempts,
                'available_in_seconds' => $availableAt,
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'error' => 'Too Many Requests',
                'message' => "Rate limit exceeded. Maximum {$maxAttempts} requests per {$decayMinutes} minute(s).",
                'retry_after' => $availableAt,
                'api_key' => $apiKey->name,
            ], 429)
            ->header('Retry-After', $availableAt)
            ->header('X-RateLimit-Limit', $maxAttempts)
            ->header('X-RateLimit-Remaining', 0);
        }

        // Request permitido, agregar headers informativos
        $remaining = RateLimiter::remaining($rateLimitKey, $maxAttempts);
        $response = $executed;

        return $response
            ->header('X-RateLimit-Limit', $maxAttempts)
            ->header('X-RateLimit-Remaining', $remaining)
            ->header('X-RateLimit-Reset', now()->addMinutes($decayMinutes)->timestamp);
    }

    /**
     * Rate limit por IP cuando no hay API Key (fallback)
     */
    private function rateLimitByIp(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        $rateLimitKey = 'ip:' . $ip;
        $maxAttempts = 60; // Más restrictivo para IPs sin API Key
        $decayMinutes = 1;

        $executed = RateLimiter::attempt(
            $rateLimitKey,
            $maxAttempts,
            function () use ($request, $next) {
                return $next($request);
            },
            $decayMinutes * 60
        );

        if (!$executed) {
            $availableAt = RateLimiter::availableIn($rateLimitKey);

            Log::channel('fotomultas')->warning('Rate limit exceeded for IP', [
                'ip' => $ip,
                'url' => $request->fullUrl(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'error' => 'Too Many Requests',
                'message' => "Rate limit exceeded. Maximum {$maxAttempts} requests per minute.",
                'retry_after' => $availableAt,
            ], 429)->header('Retry-After', $availableAt);
        }

        return $executed;
    }
}