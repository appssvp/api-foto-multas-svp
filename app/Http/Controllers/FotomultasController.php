<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\Fotomulta;
use Carbon\Carbon;

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
     *     summary="Obtener detecciones de fotomultas",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="limit", type="integer", example=50, description="Número de registros a retornar"),
     *             @OA\Property(property="offset", type="integer", example=0, description="Offset para paginación")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de detecciones",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="recordId", type="string"),
     *                 @OA\Property(property="plateNum", type="string"),
     *                 @OA\Property(property="carSpeed", type="integer"),
     *                 @OA\Property(property="createTime", type="string"),
     *                 @OA\Property(property="channelInfoVO", type="object")
     *             )
     *         )
     *     )
     * )
     */
    public function detecciones(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 50);
        $offset = $request->input('offset', 0);

        $fotomultas = Fotomulta::orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $detecciones = $fotomultas->map(function ($fotomulta) {
            return $this->mapFotomultaToDeteccion($fotomulta);
        });

        return response()->json($detecciones);
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

    /**
     * Mapea una fotomulta al formato de detección requerido
     */
    private function mapFotomultaToDeteccion($fotomulta): array
    {
        // Generar recordId siempre como cadena aleatoria de 32 caracteres hexadecimales
        $recordId = bin2hex(random_bytes(16)); // Genera 32 caracteres hexadecimales aleatorios

        // Formatear createTime al formato requerido
        $createTime = null;
        if ($fotomulta->fecha_infraccion && $fotomulta->hora_infraccion) {
            $datetime = Carbon::parse($fotomulta->fecha_infraccion . ' ' . $fotomulta->hora_infraccion);
            $createTime = $datetime->format('Ymd\THis\Z');
        }

        // Usar directamente el valor de la columna color
        $carColor = $fotomulta->color;

        // Construir channelInfoVO
        $channelMappings = $this->getChannelMappings($fotomulta->localida);
        
        $channelInfoVO = [
            'channelName' => $channelMappings['channelName'] ?: $fotomulta->localida,
            'state' => 1, // Asumimos activo
            'gpsX' => $fotomulta->geom_lat ? (float) $fotomulta->geom_lat : null,
            'gpsY' => $fotomulta->geom_lng ? (float) $fotomulta->geom_lng : null,
            'channelCode' => $channelMappings['channelCode'] ?: $fotomulta->imei,
        ];

        // Construir imgList con rutas completas
        $imgList = [];
        $imgFields = ['img1', 'img2', 'img3'];
        $radarId = $this->getRadarId($fotomulta->localida);
        
        foreach ($imgFields as $index => $field) {
            if ($fotomulta->$field) {
                // Construir la ruta: token_generado/id_radar/id_ticket/imagen
                $imgUrl = $recordId . '/' . $radarId . '/' . $fotomulta->ticket_id . '/' . $fotomulta->$field;
                
                $imgList[] = [
                    'imgUrl' => $imgUrl,
                    'imgIdx' => $index + 1,
                    'imgType' => $index === 2 ? 2 : 0, // El tercer elemento tiene tipo 2
                ];
            }
        }

        return [
            'carWayCode' => null,
            'ticketUrl' => null,
            'plateType' => 0, // Cambiado de null a 0
            'carDirect' => 0, // Cambiado de null a 0
            'dealStatus' => 0, // Cambiado de null a 0
            'plateNum' => $fotomulta->placa,
            'carSpeed' => $fotomulta->velocidad_detectada ? (int) $fotomulta->velocidad_detectada : null,
            'vehicleManufacturer' => $fotomulta->marca,
            'recordId' => $recordId,
            'recType' => 1,
            'snapHeadstock' => 0, // Cambiado de null a 0
            'carColor' => $carColor,
            'carType' => $fotomulta->tipo_vehiculo,
            'intervalCode' => null,
            'createTime' => $createTime,
            'channelInfoVO' => $channelInfoVO,
            'stopTime' => null,
            'intervalName' => null,
            'id' => $fotomulta->ticket_id, // Usar ticket_id en lugar de id
            'capStartTime' => $fotomulta->fecha_infraccion,
            'capTime' => $createTime,
            'channelCode' => $channelMappings['channelCode'] ?: $fotomulta->imei,
            'imgList' => $imgList,
        ];
    }

    /**
     * Obtiene los mapeos de channelName y channelCode según la localidad
     */
    private function getChannelMappings(?string $localidad): array
    {
        $mappings = [
            'Guardian Pro Ancla-Gasa' => [
                'channelName' => '003-SVP-GPA-AUP-47550',
                'channelCode' => '1000003$1$0$0'
            ],
            'Guardian Pro Quinceañeras' => [
                'channelName' => '001-SVP-GPQ-AUP-47549',
                'channelCode' => '1000001$1$0$0'
            ],
            'Guardian Pro Torres Sur' => [
                'channelName' => '002-SVP-GPT-AUP-47548',
                'channelCode' => '1000002$2$0$0'
            ]
        ];

        return $mappings[$localidad] ?? ['channelName' => null, 'channelCode' => null];
    }

    /**
     * Obtiene el ID del radar según la localidad
     */
    private function getRadarId(?string $localidad): ?string
    {
        $radarIds = [
            'Guardian Pro Ancla-Gasa' => '47550',
            'Guardian Pro Quinceañeras' => '47549',
            'Guardian Pro Torres Sur' => '47548'
        ];

        return $radarIds[$localidad] ?? null;
    }

    /**
     * Mapea nombre de color a número
     */
    private function mapColorToNumber(?string $color): ?int
    {
        if (!$color) return null;

        $colorMap = [
            'blanco' => 1,
            'gris' => 2,
            'negro' => 3,
            'azul' => 4,
            'rojo' => 5,
            'verde' => 6,
            'amarillo' => 7,
            'cafe' => 8,
            'naranja' => 9,
            'plata' => 10,
        ];

        $colorLower = strtolower(trim($color));
        return $colorMap[$colorLower] ?? null;
    }
}