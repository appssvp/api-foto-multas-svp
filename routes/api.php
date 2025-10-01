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


// Ruta protegida para recibir los datos desde la otra aplicación.
Route::post('/registros-validados', [RecepcionFotomultaController::class, 'store'])->middleware('auth:sanctum');

Route::get('/health', [FotomultasController::class, 'health']);

// Ruta de login con límite de intentos
Route::middleware('throttle:login')->group(function () {
    Route::post('/auth/login', [FotomultasController::class, 'login']);
});

// Rutas protegidas para el consumidor de la API (ej. SIMUCI)
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/detecciones', [FotomultasController::class, 'detecciones']);
    Route::post('/auth/logout', [FotomultasController::class, 'logout']);

    Route::middleware('throttle:images')->group(function () {
        Route::get('/imagenes/{imgUrl}', [FotomultasController::class, 'imagenes'])->where('imgUrl', '.*');
    });
});