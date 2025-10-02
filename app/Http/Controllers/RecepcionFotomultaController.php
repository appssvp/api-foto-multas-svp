<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Fotomulta;

class RecepcionFotomultaController extends Controller
{
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
                'plateNum' => 'nullable|string|max:20', // CAMBIO: nullable en vez de required
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
                Fotomulta::updateOrCreate(
                    ['ticket_id' => $deteccion['recordId']],
                    [
                        'placa' => $deteccion['plateNum'] ?? null,
                        'velocidad_detectada' => $deteccion['carSpeed'] ?? null,
                        'fecha_infraccion' => isset($deteccion['capTime']) ? \Carbon\Carbon::parse($deteccion['capTime'])->toDateString() : null,
                        'hora_infraccion' => isset($deteccion['capTime']) ? \Carbon\Carbon::parse($deteccion['capTime'])->toTimeString() : null,
                        'carril' => $deteccion['carWayCode'] ?? null,
                        'nombre_radar' => $deteccion['channelInfoVO']['channelName'] ?? null,
                        'localida' => $deteccion['channelInfoVO']['channelName'] ?? null,
                        'tipo_vehiculo' => $deteccion['carType'] ?? null,
                        'color' => $deteccion['carColor'] ?? null,
                        'geom_lat' => $deteccion['channelInfoVO']['gpsX'] ?? null,
                        'geom_lng' => $deteccion['channelInfoVO']['gpsY'] ?? null,
                        'imei' => $deteccion['channelCode'] ?? null,
                        'img1' => isset($deteccion['imgList'][0]) ? $deteccion['imgList'][0]['imgUrl'] : null,
                        'img2' => isset($deteccion['imgList'][1]) ? $deteccion['imgList'][1]['imgUrl'] : null,
                        'img3' => isset($deteccion['imgList'][2]) ? $deteccion['imgList'][2]['imgUrl'] : null,
                    ]
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
}