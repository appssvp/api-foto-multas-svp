<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FotomultasController;

// Health check (público - sin rate limit)
Route::get('/health', [FotomultasController::class, 'health']);

// Rate limiting por IP para endpoints públicos
Route::middleware('throttle:login')->group(function () {
    Route::post('/auth/login', [FotomultasController::class, 'login']);
});

// Endpoints protegidos con autenticación y rate limiting
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    
    // Endpoint Detecciones - Rate limit moderado
    Route::post('/detecciones', [FotomultasController::class, 'detecciones']);
    
    // Endpoint Imágenes - Rate limit más permisivo para recursos
    Route::middleware('throttle:images')->group(function () {
        Route::get('/imagenes/{imgUrl}', [FotomultasController::class, 'imagenes'])->where('imgUrl', '.*');
    });
    
    // Logout - Rate limit básico
    Route::post('/auth/logout', [FotomultasController::class, 'logout']);
});

// Rutas administrativas con rate limiting estricto
Route::middleware(['auth:sanctum', 'throttle:admin'])->group(function () {
    // Aquí puedes agregar endpoints administrativos en el futuro
    // Route::get('/admin/stats', [AdminController::class, 'stats']);
});