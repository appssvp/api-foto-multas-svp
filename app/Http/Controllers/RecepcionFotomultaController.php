<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\Fotomulta;
use Carbon\Carbon;

class RecepcionFotomultaController extends Controller
{
    private const ARTICULO_CONST   = '9';
    private const FRACCION_CONST   = 'l';
    private const PARRAFO_CONST    = 'l';
    private const MOTIVACION_CONST = 'EXCEDER LOS LIMITES DE VELOCIDAD PERMITIDOS';

    public function store(Request $request)
    {
        $detecciones = $request->json()->all();

        if (!is_array($detecciones)) {
            return response()->json(['message' => 'El cuerpo de la petición debe ser un arreglo de detecciones.'], 400);
        }

        $errores = [];
        $registrosCreados = 0;

        foreach ($detecciones as $index => $deteccion) {
            $validator = Validator::make($deteccion, [
                'plateNum' => 'nullable|string|max:20',
                'carSpeed' => 'nullable|integer',
                'capTime' => 'nullable|string',
                'recordId' => 'required|string|unique:fotomultas,ticket_id',
                'carWayCode' => 'nullable|integer',
                'channelInfoVO' => 'nullable|array',
                'channelInfoVO.channelName' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                $errores[] = [
                    'index' => $index,
                    'errores' => $validator->errors()
                ];
                continue;
            }

            try {
                // Mapear datos completos
                $mappedData = $this->mapDeteccionToFotomulta($deteccion);

                Fotomulta::updateOrCreate(
                    ['ticket_id' => $deteccion['recordId']],
                    $mappedData
                );
                $registrosCreados++;
            } catch (\Exception $e) {
                Log::error('Error al guardar fotomulta recibida por API.', [
                    'recordId' => $deteccion['recordId'] ?? 'N/A',
                    'error' => $e->getMessage()
                ]);
                $errores[] = [
                    'recordId' => $deteccion['recordId'] ?? 'N/A',
                    'error' => 'Error interno al procesar el registro.'
                ];
            }
        }

        if (count($errores) > 0) {
            return response()->json([
                'message' => 'Se procesó el lote con algunos errores.',
                'registros_creados' => $registrosCreados,
                'errores_detalle' => $errores,
            ], 207);
        }

        return response()->json([
            'message' => "Se procesaron {$registrosCreados} registros exitosamente."
        ], 201);
    }

    private function mapDeteccionToFotomulta(array $deteccion): array
    {
        $channelName = $deteccion['channelInfoVO']['channelName'] ?? null;
        $siteMetadata = $this->getSiteMetadata($channelName);

        // Parsear fecha y hora
        $carbonDate = null;
        if (isset($deteccion['capTime'])) {
            try {
                $carbonDate = Carbon::parse($deteccion['capTime']);
            } catch (\Exception $e) {
                Log::warning('Error parseando capTime', ['capTime' => $deteccion['capTime']]);
            }
        }

        // Coordenadas con fallback
        $latitudFinal = (float) ($deteccion['channelInfoVO']['gpsX'] ?? 0);
        $longitudFinal = (float) ($deteccion['channelInfoVO']['gpsY'] ?? 0);

        if ($latitudFinal == 0 || $longitudFinal == 0) {
            $fallbackCoords = $this->getCoordinatesByChannelName($channelName);
            if ($fallbackCoords) {
                $latitudFinal = $fallbackCoords['x'];
                $longitudFinal = $fallbackCoords['y'];
            }
        }

        return [
            'ticket_id' => $deteccion['recordId'],
            'placa' => Str::upper(trim($deteccion['plateNum'] ?? '')),
            'velocidad_detectada' => $deteccion['carSpeed'] ?? null,
            'velocidad_permitida' => 80,
            'fecha_infraccion' => $carbonDate?->toDateString(),
            'hora_infraccion' => $carbonDate?->toTimeString(),
            'carril' => $deteccion['carWayCode'] ?? null,
            'nombre_radar' => $siteMetadata['nombre_radar'] ?? $channelName,
            'localida' => $channelName,
            'tipo_vehiculo' => $deteccion['carType'] ?? null,
            'color' => $deteccion['carColor'] ?? null,
            'geom_lat' => $latitudFinal,
            'geom_lng' => $longitudFinal,
            'imei' => $deteccion['serial'] ?? null, 
            'img1' => $deteccion['imgList'][0]['imgUrl'] ?? null,
            'img2' => $deteccion['imgList'][1]['imgUrl'] ?? null,
            'img3' => $deteccion['imgList'][3]['imgUrl'] ?? null,
            'calle' => $siteMetadata['calle'] ?? null,
            'entre_calle_1' => $siteMetadata['entre_calle_1'] ?? null,
            'entre_calle_2' => $siteMetadata['entre_calle_2'] ?? null,
            'colonia' => $siteMetadata['colonia'] ?? null,
            'codigo_postal' => $siteMetadata['codigo_postal'] ?? null,
            'alcaldia' => $siteMetadata['alcaldia'] ?? null,
            'articulo' => self::ARTICULO_CONST,
            'fraccion' => self::FRACCION_CONST,
            'parrafo' => self::PARRAFO_CONST,
            'motivacion' => self::MOTIVACION_CONST,
            'usuario_no' => null,
            'folio' => null,
            'marca' => null,
            'modelo' => null,
            'modelo_dispositivo' => $deteccion['cameraModel'] ?? null, 
            'tipo_vehiculo' => null,
            'servicio_publico' => null,
            'evidencia' => null,
            'apellido_paterno_radar' => null,
            'apellido_materno_radar' => null,
        ];
    }

    private function getSiteMetadata(?string $location): array
    {
        $loc = Str::lower((string) $location);

        // Torres Sur
        if (Str::contains($loc, 'torres sur') || Str::contains($loc, '002-svp-gpt')) {
            return [
                'nombre_radar'  => 'CAMARA RADAR TORRES SUR',
                'calle'         => 'AV. CARLOS LAZO',
                'entre_calle_1' => 'ESQ. CON AV. LAS TORRES',
                'entre_calle_2' => null,
                'colonia'       => 'TORRES DE POTRERO',
                'codigo_postal' => '1840',
                'alcaldia'      => 'ÁLVARO OBREGÓN',
            ];
        }

        // Quinceañeras
        if (Str::contains($loc, 'quinceañeras') || Str::contains($loc, 'quinceaneras') || Str::contains($loc, '001-svp-gpq')) {
            return [
                'nombre_radar'  => 'CAMARA RADAR QUINCEAÑERAS',
                'calle'         => 'AV. LUIS CABRERA',
                'entre_calle_1' => 'CALLE PACHUCA',
                'entre_calle_2' => 'NARANJOS',
                'colonia'       => 'SAN JERONIMO ACULCO',
                'codigo_postal' => '10400',
                'alcaldia'      => 'LA MAGDALENA CONTRERAS',
            ];
        }

        // Ancla-Gasa
        if (Str::contains($loc, 'ancla-gasa') || Str::contains($loc, 'ancla gasa') || Str::contains($loc, '003-svp-gpa')) {
            return [
                'nombre_radar'  => 'CAMARA RADAR ANCLA GASA',
                'calle'         => 'AV. LUIS CABRERA',
                'entre_calle_1' => 'ESQ. CON AV. CONTRERAS',
                'entre_calle_2' => null,
                'colonia'       => 'SAN JERONIMO LIDICE',
                'codigo_postal' => '10200',
                'alcaldia'      => 'LA MAGDALENA CONTRERAS',
            ];
        }

        return [];
    }

    private function getCoordinatesByChannelName($channelName): ?array
    {
        $coordinates = [
            '003-SVP-GPA-AUP-47550' => ['x' => 19.322212, 'y' => -99.222018],
            '001-SVP-GPQ-AUP-47549' => ['x' => 19.320707, 'y' => -99.227522],
            '002-SVP-GPT-AUP-47548' => ['x' => 19.332965, 'y' => -99.237627],
            'Guardian Pro Ancla-Gasa' => ['x' => 19.322212, 'y' => -99.222018],
            'Guardian Pro Quinceañeras' => ['x' => 19.320707, 'y' => -99.227522],
            'Guardian Pro Torres Sur' => ['x' => 19.332965, 'y' => -99.237627],
        ];

        return $coordinates[$channelName] ?? null;
    }
}
