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
     *             @OA\Property(property="fecha_inicio", type="string", format="datetime", example="2025-09-06 00:00:00", description="Fecha y hora de inicio"),
     *             @OA\Property(property="fecha_fin", type="string", format="datetime", example="2025-09-06 23:59:59", description="Fecha y hora de fin")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de detecciones (máximo 1000 registros)",
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
     *     ),
     *     @OA\Response(response=422, description="Error de validación"),
     *     @OA\Response(response=401, description="No autorizado")
     * )
     */
    public function detecciones(Request $request): JsonResponse
    {
        // Validar solo los parámetros de fecha
        $validated = $request->validate([
            'fecha_inicio' => 'nullable|date_format:Y-m-d H:i:s',
            'fecha_fin' => 'nullable|date_format:Y-m-d H:i:s'
        ]);

        $fechaInicio = $validated['fecha_inicio'] ?? null;
        $fechaFin = $validated['fecha_fin'] ?? null;

        // Validar que fecha_inicio sea menor que fecha_fin
        if ($fechaInicio && $fechaFin && $fechaInicio > $fechaFin) {
            return response()->json([
                'error' => 'La fecha de inicio no puede ser mayor que la fecha de fin'
            ], 422);
        }

        // Construir query base
        $query = Fotomulta::query();

        // Aplicar filtros de fecha si están presentes
        if ($fechaInicio) {
            $query->where(function ($q) use ($fechaInicio) {
                $q->whereRaw("CONCAT(fecha_infraccion, ' ', COALESCE(hora_infraccion, '00:00:00')) >= ?", [$fechaInicio]);
            });
        }

        if ($fechaFin) {
            $query->where(function ($q) use ($fechaFin) {
                $q->whereRaw("CONCAT(fecha_infraccion, ' ', COALESCE(hora_infraccion, '23:59:59')) <= ?", [$fechaFin]);
            });
        }

        // Ejecutar query con límite fijo de 1000 registros
        $fotomultas = $query->orderByRaw("CONCAT(fecha_infraccion, ' ', COALESCE(hora_infraccion, '00:00:00')) DESC")
            ->limit(1000)
            ->get();

        $detecciones = $fotomultas->map(function ($fotomulta) {
            return $this->mapFotomultaToDeteccion($fotomulta);
        });

        return response()->json($detecciones);
    }

    /**
     * @OA\Get(
     *     path="/api/imagenes/{imgUrl}",
     *     tags={"Imágenes"},
     *     summary="Obtener imagen de fotomulta por URL",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="imgUrl",
     *         in="path",
     *         required=true,
     *         description="URL completa de la imagen (formato: recordId/radarId/ticketId/filename)",
     *         @OA\Schema(type="string", example="53a13cba07c471ae1d8e7a6cd628b5cd/47548/135733046/full_3070_3941.jpg")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Imagen encontrada",
     *         @OA\MediaType(mediaType="image/jpeg")
     *     ),
     *     @OA\Response(response=400, description="URL de imagen inválida"),
     *     @OA\Response(response=404, description="Imagen no encontrada")
     * )
     */
    public function imagenes(Request $request, $imgUrl)
    {
        // Decodificar URL si viene encoded
        $imgUrl = urldecode($imgUrl);
        
        // Validar formato de la URL: recordId/radarId/ticketId/filename
        if (!preg_match('/^([a-f0-9]{32})\/(\d{5})\/(\d+)\/([a-zA-Z0-9._-]+\.(jpg|jpeg|png|gif|bmp))$/i', $imgUrl, $matches)) {
            return response()->json(['error' => 'Formato de URL de imagen inválido'], 400);
        }

        [, $recordId, $radarId, $ticketId, $fileName] = $matches;

        // Validar que el ticketId existe en la base de datos
        $fotomulta = Fotomulta::where('ticket_id', $ticketId)->first();

        if (!$fotomulta) {
            return response()->json(['error' => 'Ticket no encontrado'], 404);
        }

        // Verificar que la imagen existe en alguna de las columnas img1, img2, img3
        $imgFields = ['img1', 'img2', 'img3'];
        $imagenEncontrada = false;
        
        foreach ($imgFields as $field) {
            if ($fotomulta->$field === $fileName) {
                $imagenEncontrada = true;
                break;
            }
        }

        if (!$imagenEncontrada) {
            return response()->json(['error' => 'Imagen no encontrada en el ticket'], 404);
        }

        // Descargar imagen desde la API externa
        return $this->descargarImagenDesdeAPI($ticketId, $fileName);
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
        $recordId = bin2hex(random_bytes(16));

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
            'state' => 1,
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
                    'imgType' => $index === 2 ? 2 : 0,
                ];
            }
        }

        return [
            'carWayCode' => 0,
            'ticketUrl' => null,
            'plateType' => 0,
            'carDirect' => 0,
            'dealStatus' => 0,
            'plateNum' => $fotomulta->placa,
            'carSpeed' => $fotomulta->velocidad_detectada ? (int) $fotomulta->velocidad_detectada : null,
            'vehicleManufacturer' => 0,
            'recordId' => $recordId,
            'recType' => 1,
            'snapHeadstock' => 0,
            'carColor' => $carColor,
            'carType' => $fotomulta->tipo_vehiculo,
            'intervalCode' => null,
            'createTime' => $createTime,
            'channelInfoVO' => $channelInfoVO,
            'stopTime' => null,
            'intervalName' => null,
            'id' => $fotomulta->ticket_id,
            'capStartTime' => $fotomulta->fecha_infraccion,
            'capTime' => $createTime,
            'channelCode' => $channelMappings['channelCode'] ?: $fotomulta->imei,
            'imgList' => $imgList,
        ];
    }

    /**
     * Descarga imagen desde la API externa
     */
    private function descargarImagenDesdeAPI($ticketId, $fileName)
    {
        $apiKey = config('services.streetsoncloud.api_key');
        $baseUrl = config('services.streetsoncloud.api_url');
        
        if (!$apiKey) {
            return response()->json(['error' => 'API key no configurada'], 500);
        }
        
        $metaUrl = "{$baseUrl}/ticket-image/{$ticketId}/{$fileName}";

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
                ->header('Cache-Control', 'public, max-age=3600');

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
}