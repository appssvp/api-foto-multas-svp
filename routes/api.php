<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FotomultasController;
use App\Http\Controllers\RecepcionFotomultaController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Ruta pública - Health check
Route::get('/health', [FotomultasController::class, 'health']);

// Ruta de login con límite de intentos (autenticación tradicional)
Route::middleware('throttle:login')->group(function () {
    Route::post('/auth/login', [FotomultasController::class, 'login']);
});

// Rutas protegidas con Sanctum (para admins que hacen login)
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [FotomultasController::class, 'logout']);
});

// Rutas protegidas con API Key (para consumidores externos como SIMUCI y servidor origen)
Route::middleware(['api.key', 'api.key.throttle', 'security'])->group(function () {
    Route::post('/detecciones', [FotomultasController::class, 'detecciones']);
    
    Route::get('/imagenes/{imgUrl}', [FotomultasController::class, 'imagenes'])
        ->where('imgUrl', '.*');
    
    Route::post('/registros-validados', [RecepcionFotomultaController::class, 'store']); // 👈 MOVIDO AQUÍ
});