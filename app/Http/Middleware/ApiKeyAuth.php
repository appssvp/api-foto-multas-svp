<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\ApiKey;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ApiKeyAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string|null  $permission  Permiso requerido opcional
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $startTime = microtime(true);

        $apiKeyValue = $request->header('X-API-Key');

        if (!$apiKeyValue) {
            Log::channel('fotomultas')->warning('API Key missing', [
                'ip' => $request->ip(),
                'url' => $request->fullUrl(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'error' => 'API Key required',
                'message' => 'Please provide X-API-Key header'
            ], 401);
        }


        if (!str_starts_with($apiKeyValue, 'apk_')) {
            Log::channel('fotomultas')->warning('Invalid API Key format', [
                'ip' => $request->ip(),
                'key_prefix' => substr($apiKeyValue, 0, 10) . '...',
            ]);

            return response()->json([
                'error' => 'Invalid API Key format',
                'message' => 'API Key must start with "apk_"'
            ], 401);
        }

        $apiKey = ApiKey::findByPlainKey($apiKeyValue);

        if (!$apiKey) {
            Log::channel('fotomultas')->warning('API Key not found', [
                'ip' => $request->ip(),
                'url' => $request->fullUrl(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'error' => 'Invalid API Key',
                'message' => 'The provided API key does not exist'
            ], 401);
        }

        // Verificar validez (activa y no expirada)
        if (!$apiKey->isValid()) {
            Log::channel('fotomultas')->warning('API Key invalid or expired', [
                'key_name' => $apiKey->name,
                'user_id' => $apiKey->user_id,
                'is_active' => $apiKey->is_active,
                'expires_at' => $apiKey->expires_at?->toISOString(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Invalid API Key',
                'message' => 'The API key is inactive or expired'
            ], 401);
        }

        // Verificar IP whitelist si está configurada
        if ($apiKey->ip_whitelist) {
            $allowedIps = array_map('trim', explode(',', $apiKey->ip_whitelist));
            $clientIp = $request->ip();

            if (!in_array($clientIp, $allowedIps)) {
                Log::channel('fotomultas')->warning('IP not whitelisted for API Key', [
                    'key_name' => $apiKey->name,
                    'user_id' => $apiKey->user_id,
                    'client_ip' => $clientIp,
                    'allowed_ips' => $allowedIps,
                    'timestamp' => now()->toISOString()
                ]);

                return response()->json([
                    'error' => 'IP not allowed',
                    'message' => 'Your IP address is not whitelisted for this API key'
                ], 403);
            }
        }

        if ($permission && !$apiKey->hasPermission($permission)) {
            Log::channel('fotomultas')->warning('API Key lacks required permission', [
                'key_name' => $apiKey->name,
                'user_id' => $apiKey->user_id,
                'required_permission' => $permission,
                'key_permissions' => $apiKey->permissions,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Permission denied',
                'message' => "This API key does not have '{$permission}' permission"
            ], 403);
        }

        // Autenticar al usuario asociado a la API Key
        $request->setUserResolver(function () use ($apiKey) {
            return $apiKey->user;
        });

        // Marcar como usada (actualizando last_used_at)
        $apiKey->markAsUsed();

        $endTime = microtime(true);
        $responseTime = round(($endTime - $startTime) * 1000, 2);

        Log::channel('fotomultas')->info('API Key authenticated successfully', [
            'key_name' => $apiKey->name,
            'user_id' => $apiKey->user_id,
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'user_agent' => $request->userAgent(),
            'auth_time_ms' => $responseTime,
            'timestamp' => now()->toISOString()
        ]);


        $request->attributes->add([
            'api_key' => $apiKey,
            'api_key_name' => $apiKey->name,
        ]);

        return $next($request);
    }
}
