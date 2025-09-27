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
     * @OA\Get(
     *     path="/api/imagenes/{ticketId}",
     *     tags={"Imágenes"},
     *     summary="Obtener imágenes de fotomulta",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="ticketId",
     *         in="path",
     *         required=true,
     *         description="ID del ticket",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="img",
     *         in="query",
     *         required=false,
     *         description="Número de imagen (1, 2, o 3). Por defecto: 1",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Imagen encontrada",
     *         @OA\MediaType(mediaType="image/jpeg")
     *     ),
     *     @OA\Response(response=404, description="Ticket o imagen no encontrada")
     * )
     */
    public function imagenes(Request $request, $ticketId)
    {
        // Buscar la fotomulta por ticket_id
        $fotomulta = Fotomulta::where('ticket_id', $ticketId)->first();

        if (!$fotomulta) {
            return response()->json(['error' => 'Ticket no encontrado'], 404);
        }

        // Determinar qué imagen mostrar (por defecto img1)
        $imgNumber = $request->query('img', 1);
        $imgField = 'img' . $imgNumber;

        if (!in_array($imgNumber, [1, 2, 3]) || !$fotomulta->$imgField) {
            return response()->json(['error' => 'Imagen no encontrada'], 404);
        }

        $imageName = $fotomulta->$imgField;

        // Descargar imagen desde la API externa (basado en tu método descargarImagenV4)
        return $this->descargarImagenDesdeAPI($ticketId, $imageName);
    }

    /**
     * Descarga imagen desde la API externa (adaptado de FotomultasV2Controller)
     */
    private function descargarImagenDesdeAPI($ticketId, $fileName)
    {
        $apiKey = "wdK23RTWnG4XvvlxNDN2K5RfJNHrHSUY7cBNB86Y";
        $metaUrl = "https://api.streetsoncloud.com/v4/ticket-image/{$ticketId}/{$fileName}";

        try {
            // 1) Obtener la URL real de la imagen
            $meta = \Illuminate\Support\Facades\Http::withHeaders([
                'x-api-key' => $apiKey,
                'X-Requested-With' => 'XMLHttpRequest',
            ])->timeout(30)->get($metaUrl);

            if ($meta->failed()) {
                return response()->json(['error' => 'No se pudo obtener URL de imagen'], 502);
            }

            $imageUrl = data_get($meta->json(), 'imageUrl');
            if (!$imageUrl) {
                return response()->json(['error' => 'URL no disponible'], 404);
            }

            // 2) Descargar imagen binaria
            $img = \Illuminate\Support\Facades\Http::timeout(60)->get($imageUrl);
            if ($img->failed()) {
                return response()->json(['error' => 'No se pudo descargar la imagen'], 502);
            }

            $mime = $img->header('Content-Type') ?? $this->mimeFromExt($fileName);

            // 3) Retornar imagen directamente
            return response($img->body(), 200)
                ->header('Content-Type', $mime)
                ->header('Cache-Control', 'public, max-age=3600'); // Cache por 1 hora

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al procesar la imagen: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Determina el tipo MIME por extensión de archivo
     */
    private function mimeFromExt($fileName)
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'          => 'image/png',
            'bmp'          => 'image/bmp',
            'gif'          => 'image/gif',
            default        => 'application/octet-stream',
        };
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