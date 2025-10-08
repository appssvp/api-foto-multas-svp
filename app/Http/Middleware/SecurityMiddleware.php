<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class SecurityMiddleware
{
    /**
     * Patrones maliciosos comunes en ataques
     */
    private array $maliciousPatterns = [
        // SQL Injection
        'sql_injection' => [
            '/(\bOR\b|\bAND\b)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?/i',
            '/UNION\s+SELECT/i',
            '/INSERT\s+INTO/i',
            '/DELETE\s+FROM/i',
            '/DROP\s+(TABLE|DATABASE)/i',
            '/UPDATE\s+\w+\s+SET/i',
            '/EXEC(\s|\()/i',
            '/WAITFOR\s+DELAY/i',
            '/SLEEP\s*\(/i',
            '/BENCHMARK\s*\(/i',
            '/--\s*$/',
            '/;\s*--/',
            '/\/\*.*\*\//',
        ],

        // XSS (Cross-Site Scripting)
        'xss' => [
            '/<script[^>]*>.*?<\/script>/is',
            '/<iframe[^>]*>.*?<\/iframe>/is',
            '/javascript:/i',
            '/on\w+\s*=\s*["\']?[^"\']*["\']?/i',
            '/<img[^>]+src[^>]*>/i',
            '/<svg[^>]*onload/i',
            '/alert\s*\(/i',
            '/document\.(cookie|write|location)/i',
            '/eval\s*\(/i',
        ],

        // Command Injection
        'command_injection' => [
            '/[;&|`$]\s*(cat|ls|rm|mv|cp|chmod|chown|kill|ps|whoami|id|uname|wget|curl|nc|bash|sh|exec)/i',
            '/\$\(.*\)/',
            '/`[^`]+`/',
            '/&&/',
            '/\|\|/',
            '/;\s*(cat|ls|rm|whoami|pwd|id)/i',
        ],

        // Path Traversal
        'path_traversal' => [
            '/\.\.[\/\\\\]/',
            '/\.\.%2[fF]/',
            '/\.\.%5[cC]/',
            '/%2e%2e[\/\\\\]/',
            '/\/etc\/(passwd|shadow|hosts)/i',
            '/\/windows\/system32/i',
            '/\/boot\.ini/i',
        ],

        // LDAP Injection
        'ldap_injection' => [
            '/\*\)\s*\(&/',
            '/\(\|/',
            '/\)\(&/',
            '/\*\|/',
        ],

        // XML/XXE Injection
        'xxe_injection' => [
            '/<!DOCTYPE.*\[.*<!ENTITY/is',
            '/<!ENTITY/i',
            '/SYSTEM\s+["\']file:/i',
            '/<\?xml.*\?>/i',
        ],

        // SSRF (Server-Side Request Forgery) - Solo en producción
        'ssrf' => [
            '/169\.254\.169\.254/',
            '/metadata\.google\.internal/',
        ],

        // NoSQL Injection
        'nosql_injection' => [
            '/\$ne\s*:/',
            '/\$gt\s*:/',
            '/\$lt\s*:/',
            '/\$where\s*:/',
            '/\$regex\s*:/',
        ],

        // File Upload Malicious Extensions
        'file_upload' => [
            '/\.(php|phtml|php3|php4|php5|phps|phar)$/i',
            '/\.(asp|aspx|asa|asax)$/i',
            '/\.(jsp|jspx)$/i',
            '/\.(sh|bash|bat|cmd|exe)$/i',
        ],

        // Remote File Inclusion
        'rfi' => [
            '/https?:\/\/.*\.(txt|php|asp|jsp)/i',
            '/ftp:\/\//i',
            '/file:\/\//i',
        ],
    ];

    /**
     * Headers sospechosos
     */
    private array $suspiciousHeaders = [
        'User-Agent',
        'Referer',
        'X-Forwarded-For',
        'X-Original-URL',
        'X-Rewrite-URL',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {

        if (app()->environment('local')) {

            $this->maliciousPatterns['ssrf'] = [
                '/169\.254\.169\.254/',
                '/metadata\.google\.internal/',

            ];
        } else {

            $this->maliciousPatterns['ssrf'] = [
                '/169\.254\.169\.254/',
                '/metadata\.google\.internal/',
                '/localhost/i',
                '/127\.0\.0\.1/',
                '/0\.0\.0\.0/',
                '/::1/',
                '/\[::1\]/',
            ];
        }

        // Verificar todas las entradas del request
        $allInputs = array_merge(
            $request->all(),
            $request->query->all(),
            $request->route() ? $request->route()->parameters() : []
        );

        // Escanear inputs
        foreach ($allInputs as $key => $value) {
            if (is_string($value)) {
                $threat = $this->detectMaliciousPattern($value);
                if ($threat) {
                    return $this->blockRequest($request, $threat, $key, $value);
                }
            } elseif (is_array($value)) {
                $threat = $this->scanArray($value);
                if ($threat) {
                    return $this->blockRequest($request, $threat['type'], $threat['key'], $threat['value']);
                }
            }
        }

        // Escanear headers sospechosos
        foreach ($this->suspiciousHeaders as $header) {
            $headerValue = $request->header($header);
            if ($headerValue) {
                $threat = $this->detectMaliciousPattern($headerValue);
                if ($threat) {
                    return $this->blockRequest($request, $threat, "header:{$header}", $headerValue);
                }
            }
        }

        // Verificar tamaño del payload
        $contentLength = $request->header('Content-Length', 0);
        if ($contentLength > 1048576) { // 1MB
            return $this->blockRequest($request, 'large_payload', 'Content-Length', $contentLength);
        }

        return $next($request);
    }

    /**
     * Escanear arrays recursivamente
     */
    private function scanArray(array $data, string $parentKey = ''): ?array
    {
        foreach ($data as $key => $value) {
            $fullKey = $parentKey ? "{$parentKey}.{$key}" : $key;
            
            if (is_string($value)) {
                $threat = $this->detectMaliciousPattern($value);
                if ($threat) {
                    return ['type' => $threat, 'key' => $fullKey, 'value' => $value];
                }
            } elseif (is_array($value)) {
                $result = $this->scanArray($value, $fullKey);
                if ($result) {
                    return $result;
                }
            }
        }
        return null;
    }

    /**
     * Detectar patrones maliciosos en un string
     */
    private function detectMaliciousPattern(string $input): ?string
    {
        // Decodificar URL encoding y HTML entities para evitar evasión
        $decoded = urldecode($input);
        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5);

        foreach ($this->maliciousPatterns as $threatType => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $decoded)) {
                    return $threatType;
                }
            }
        }

        return null;
    }

    /**
     * Bloquear request y registrar el ataque
     */
    private function blockRequest(Request $request, string $threatType, string $inputKey, mixed $inputValue): Response
    {
        // Truncar valor para logging
        $truncatedValue = is_string($inputValue) 
            ? substr($inputValue, 0, 200) 
            : json_encode($inputValue);

        Log::channel('fotomultas')->warning('Security threat detected and blocked', [
            'threat_type' => $threatType,
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'user_agent' => $request->userAgent(),
            'input_key' => $inputKey,
            'input_value' => $truncatedValue,
            'user_id' => Auth::id(),
            'timestamp' => now()->toISOString(),
        ]);

        return response()->json([
            'error' => 'Security threat detected',
            'message' => 'Your request has been blocked due to suspicious content',
            'threat_type' => $threatType,
            'reference_id' => uniqid('SEC-', true),
        ], 403);
    }
}