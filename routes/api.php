<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FotomultasController;

// 1. Endpoint de Autenticación (público)
Route::post('/auth/login', [FotomultasController::class, 'login']);

// 2 y 3. Endpoints protegidos con token
Route::middleware('auth:sanctum')->group(function () {
    
    // 2. Endpoint Detecciones
    Route::post('/detecciones', [FotomultasController::class, 'detecciones']);
    
    // 3. Endpoint Imágenes  
    Route::post('/imagenes', [FotomultasController::class, 'imagenes']);
    
    // Logout
    Route::post('/auth/logout', [FotomultasController::class, 'logout']);
});