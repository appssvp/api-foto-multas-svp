<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\User;

/**
 * @OA\Info(
 *     title="Fotomultas API",
 *     version="1.0.0",
 *     description="API para sistema de fotomultas"
 * )
 * 
 * @OA\Server(
 *     url="http://localhost:8000",
 *     description="Servidor de desarrollo"
 * )
 * 
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 */
class FotomultasController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/auth/login",
     *     tags={"Auth"},
     *     summary="Autenticación",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@fotomultas.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login exitoso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="token", type="string", example="1|abc123xyz..."),
     *             @OA\Property(property="user", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Credenciales inválidas")
     * )
     */
public function login(Request $request): JsonResponse
{
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (!Auth::attempt($request->only('email', 'password'))) {
        return response()->json([
            'success' => false,
            'message' => 'Credenciales inválidas'
        ], 401);
    }

    $user = Auth::user();
    $token = $user->createToken('fotomultas-api')->plainTextToken;

    return response()->json([
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email
        ]
    ]);
}

    /**
     * @OA\Post(
     *     path="/api/detecciones",
     *     tags={"Detecciones"},
     *     summary="Endpoint de detecciones",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Detecciones procesadas")
     * )
     */
    public function detecciones(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Método de detecciones']);
    }

    /**
     * @OA\Post(
     *     path="/api/imagenes",
     *     tags={"Imágenes"},
     *     summary="Endpoint de imágenes",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Imágenes procesadas")
     * )
     */
    public function imagenes(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Método de imágenes']);
    }

    /**
     * @OA\Post(
     *     path="/api/auth/logout",
     *     tags={"Auth"},
     *     summary="Cerrar sesión",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Logout exitoso")
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Logout exitoso']);
    }
}
