<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FotomultasController;

// Health check (público - no requiere autenticación)
Route::get('/health', [FotomultasController::class, 'health']);

// 1. Endpoint de Autenticación (público) - Rate limit más estricto
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/login', [FotomultasController::class, 'login']);
});

// 2 y 3. Endpoints protegidos con token - Rate limit normal
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    
    // 2. Endpoint Detecciones
    Route::post('/detecciones', [FotomultasController::class, 'detecciones']);
    
    // 3. Endpoint Imágenes con URL completa como parámetro - Rate limit más relajado para imágenes
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/imagenes/{imgUrl}', [FotomultasController::class, 'imagenes'])->where('imgUrl', '.*');
    });
    
    // Logout
    Route::post('/auth/logout', [FotomultasController::class, 'logout']);
});