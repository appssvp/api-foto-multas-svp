<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\Fotomulta;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Jobs\ProcessImageDownload;

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
        $validated = $request->validate([
            'email' => 'required|email:rfc,dns|max:255',
            'password' => 'required|string|min:6|max:255',
        ]);

        // Sanitizar email
        $email = filter_var(trim($validated['email']), FILTER_SANITIZE_EMAIL);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::channel('fotomultas')->warning('Login attempt with invalid email', [
                'email' => $email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Email inválido'
            ], 422);
        }

        if (!Auth::attempt(['email' => $email, 'password' => $validated['password']])) {
            Log::channel('fotomultas')->warning('Failed login attempt', [
                'email' => $email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Credenciales inválidas'
            ], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('fotomultas-api')->plainTextToken;

        Log::channel('fotomultas')->info('Successful login', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toISOString()
        ]);

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
        $startTime = microtime(true);

        // Validar solo los parámetros de fecha
        $validated = $request->validate([
            'fecha_inicio' => 'nullable|date_format:Y-m-d H:i:s',
            'fecha_fin' => 'nullable|date_format:Y-m-d H:i:s'
        ]);

        $fechaInicio = $validated['fecha_inicio'] ?? null;
        $fechaFin = $validated['fecha_fin'] ?? null;

        Log::channel('fotomultas')->info('Detecciones request', [
            'user_id' => Auth::id(),
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'ip' => $request->ip(),
            'timestamp' => now()->toISOString()
        ]);

        // Validar que fecha_inicio sea menor que fecha_fin
        if ($fechaInicio && $fechaFin && $fechaInicio > $fechaFin) {
            Log::channel('fotomultas')->warning('Invalid date range in detecciones', [
                'user_id' => Auth::id(),
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin
            ]);

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

        // 🚀 CAMBIO PRINCIPAL: Precargar TODAS las imágenes automáticamente en background
        $this->precacheAllImagesFromDetections($detecciones);

        $endTime = microtime(true);
        $responseTime = round(($endTime - $startTime) * 1000, 2);

        Log::channel('fotomultas')->info('Detecciones response', [
            'user_id' => Auth::id(),
            'records_found' => $detecciones->count(),
            'response_time_ms' => $responseTime,
            'filters_applied' => ['fecha_inicio' => $fechaInicio, 'fecha_fin' => $fechaFin],
            'timestamp' => now()->toISOString()
        ]);

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
        $startTime = microtime(true);

        // Decodificar URL si viene encoded
        $imgUrl = urldecode($imgUrl);

        Log::channel('fotomultas')->info('Image request received', [
            'user_id' => Auth::id(),
            'image_url' => $imgUrl,
            'ip' => $request->ip(),
            'timestamp' => now()->toISOString()
        ]);

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

        // 🚀 CAMBIO: Usar cache optimizado para servir imagen
        return $this->serveImageFromCache($imgUrl, $ticketId, $fileName, $startTime);
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
     * @OA\Get(
     *     path="/api/health",
     *     tags={"Health"},
     *     summary="Health check endpoint",
     *     @OA\Response(
     *         response=200,
     *         description="API funcionando correctamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="ok"),
     *             @OA\Property(property="database", type="string", example="connected"),
     *             @OA\Property(property="records", type="integer", example=1250),
     *             @OA\Property(property="timestamp", type="string", example="2025-01-15T10:30:45.000000Z")
     *         )
     *     ),
     *     @OA\Response(response=500, description="Error en el sistema")
     * )
     */
    public function health(): JsonResponse
    {
        try {
            // Verificar conexión a base de datos
            DB::connection()->getPdo();

            // Verificar tabla principal con una consulta simple
            $count = Fotomulta::count();

            return response()->json([
                'status' => 'ok',
                'database' => 'connected',
                'records' => $count,
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'database' => 'disconnected',
                'message' => 'Database connection failed',
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    /**
     * 🆕 NUEVO: Precargar TODAS las imágenes de las detecciones automáticamente
     */
    private function precacheAllImagesFromDetections($detecciones)
    {
        $startTime = microtime(true);
        $totalImages = 0;

        foreach ($detecciones as $deteccion) {
            // Procesar todas las imágenes del imgList
            if (isset($deteccion['imgList']) && is_array($deteccion['imgList'])) {
                foreach ($deteccion['imgList'] as $imagen) {
                    if (isset($imagen['imgUrl'])) {
                        $cacheKey = 'fotomulta_image_' . md5($imagen['imgUrl']);

                        // Solo procesar si no está en cache
                        if (!Cache::has($cacheKey)) {
                            ProcessImageDownload::dispatch($imagen['imgUrl'])
                                ->onQueue('images')
                                ->delay(now()->addSeconds(rand(1, 10))); // Distribuir carga

                            $totalImages++;
                        }
                    }
                }
            }
        }

        $endTime = microtime(true);
        $responseTime = round(($endTime - $startTime) * 1000, 2);

        Log::channel('fotomultas')->info('Background image processing initiated', [
            'user_id' => Auth::id(),
            'detections_processed' => count($detecciones),
            'total_images_queued' => $totalImages,
            'queue_time_ms' => $responseTime,
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * 🆕 NUEVO: Servir imagen desde cache optimizado
     */
    private function serveImageFromCache($imgUrl, $ticketId, $fileName, $startTime)
    {
        $cacheKey = 'fotomulta_image_' . md5($imgUrl);

        // Verificar si está en cache
        if (Cache::has($cacheKey)) {
            $imageData = Cache::get($cacheKey);
            $mimeType = $this->mimeFromExt($fileName);

            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            Log::channel('fotomultas')->info('Image served from cache', [
                'user_id' => Auth::id(),
                'image_url' => $imgUrl,
                'response_time_ms' => $responseTime,
                'cache_hit' => true,
                'image_size_kb' => round(strlen(base64_decode($imageData)) / 1024, 2),
                'timestamp' => now()->toISOString()
            ]);

            return response(base64_decode($imageData), 200)
                ->header('Content-Type', $mimeType)
                ->header('Cache-Control', 'public, max-age=21600')
                ->header('X-Cache-Status', 'HIT');
        }

        // Si no está en cache, descargar directamente (fallback)
        Log::channel('fotomultas')->warning('Image not in cache, falling back to direct download', [
            'user_id' => Auth::id(),
            'image_url' => $imgUrl,
            'cache_key' => $cacheKey
        ]);

        return $this->descargarImagenDesdeAPI($ticketId, $fileName);
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
            // MODIFICACIÓN: Se cambia el formato de la fecha para coincidir con el solicitado.
            $createTime = $datetime->format('Ymd\THis\Z');
        }

        // Asegurar que carColor sea siempre un string y nunca nulo. '99' es 'Desconocido'.
        $carColor = (string) ($fotomulta->color ?? '99');

        // Construir channelInfoVO con tipos de dato correctos
        $channelMappings = $this->getChannelMappings($fotomulta->localida);

        $channelInfoVO = [
            'channelName' => $channelMappings['channelName'] ?: $fotomulta->localida,
            'state' => '1',
            'gpsX' => $fotomulta->geom_lat ? (string) $fotomulta->geom_lat : null,
            'gpsY' => $fotomulta->geom_lng ? (string) $fotomulta->geom_lng : null,
            'channelCode' => $channelMappings['channelCode'] ?: $fotomulta->imei,
        ];

        // Construir imgList
        $imgList = [];
        $imgFields = ['img1', 'img2', 'img3'];
        $radarId = $this->getRadarId($fotomulta->localida);

        foreach ($imgFields as $index => $field) {
            if ($fotomulta->$field) {
                $imgUrl = $recordId . '/' . $radarId . '/' . $fotomulta->ticket_id . '/' . $fotomulta->$field;

                $imgList[] = [
                    'imgUrl' => $imgUrl,
                    'imgIdx' => $index + 1,
                    'imgType' => $index === 2 ? 2 : 0,
                ];
            }
        }

        return [
            'carWayCode' => $fotomulta->carril, // MODIFICACIÓN: Se usa el valor de la columna 'carril'.
            'ticketUrl' => null,
            'plateType' => '0',
            'carDirect' => '0',
            'dealStatus' => null,
            'plateNum' => $fotomulta->placa,
            'carSpeed' => $fotomulta->velocidad_detectada ? (int) $fotomulta->velocidad_detectada : null,
            'vehicleManufacturer' => '0',
            'recordId' => $recordId,
            'recType' => 1,
            'snapHeadstock' => null,
            'carColor' => $carColor,
            'carType' => $fotomulta->tipo_vehiculo,
            'intervalCode' => null,
            'createTime' => $createTime,
            'channelInfoVO' => $channelInfoVO,
            'stopTime' => null,
            'intervalName' => null,
            'id' => null,
            'capStartTime' => null,
            'capTime' => $createTime,
            'channelCode' => $channelMappings['channelCode'] ?: $fotomulta->imei,
            'imgList' => $imgList,
        ];
    }

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
            $meta = Http::withHeaders([
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
            $img = Http::timeout(60)->get($imageUrl);
            if ($img->failed()) {
                return response()->json(['error' => 'No se pudo descargar la imagen'], 502);
            }

            $mime = $img->header('Content-Type') ?? $this->mimeFromExt($fileName);

            // 3) Retornar imagen directamente
            return response($img->body(), 200)
                ->header('Content-Type', $mime)
                ->header('Cache-Control', 'public, max-age=3600')
                ->header('X-Cache-Status', 'MISS');
        } catch (\Exception $e) {
            Log::channel('fotomultas')->error('Direct image download failed', [
                'ticket_id' => $ticketId,
                'filename' => $fileName,
                'error' => $e->getMessage()
            ]);

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
