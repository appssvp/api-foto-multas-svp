<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Configuración de Fotomultas
    |--------------------------------------------------------------------------
    */

    // Timeouts para APIs
    'timeouts' => [
        'api' => env('API_TIMEOUT', 30),
        'image' => env('IMAGE_TIMEOUT', 60),
    ],

    // Storage para imágenes
    'storage' => [
        'images_path' => env('FOTOMULTAS_STORAGE_PATH', 'fotomultas/images'),
    ],

    // Configuración de colas
    'queue' => [
        'retry_after' => env('QUEUE_RETRY_AFTER', 300),
        'max_jobs' => env('QUEUE_MAX_JOBS', 50),
    ],

    // Monitoring y logging
    'monitoring' => [
        'query_log' => env('ENABLE_QUERY_LOG', false),
        'performance_log' => env('ENABLE_PERFORMANCE_LOG', true),
    ],

    // Seguridad
    'security' => [
        'force_https' => env('FORCE_HTTPS', false),
        'secure_cookies' => env('SECURE_COOKIES', false),
    ],

    // Rate limiting personalizado
    'rate_limits' => [
        'login_attempts' => env('RATE_LIMIT_LOGIN_ATTEMPTS', 5),
        'api_requests' => env('RATE_LIMIT_API_REQUESTS', 100),
        'image_requests' => env('RATE_LIMIT_IMAGE_REQUESTS', 200),
    ],
];