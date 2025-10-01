<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Fotomulta; // Asegúrate de que este es el modelo correcto en tu app de destino.

class RecepcionFotomultaController extends Controller
{
    /**
     * Almacena una o más detecciones de fotomultas.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // El job envía un arreglo de detecciones, por lo que validamos que la petición sea un arreglo.
        $detecciones = $request->json()->all();

        if (!is_array($detecciones)) {
            return response()->json(['message' => 'El cuerpo de la petición debe ser un arreglo de detecciones.'], 400);
        }

        $errores = [];
        $registrosCreados = 0;

        foreach ($detecciones as $index => $deteccion) {
            // 1. Validar cada detección individualmente.
            // Esta validación debe coincidir con la estructura que envía el job.
            $validator = Validator::make($deteccion, [
                'plateNum' => 'required|string|max:20',
                'carSpeed' => 'required|integer',
                'capTime' => 'required|string',
                'recordId' => 'required|string|unique:fotomultas,record_id_origen', // Asumiendo que guardas el recordId original.
                // Añade aquí el resto de validaciones para los campos que recibes...
                'carWayCode' => 'nullable|integer',
                'channelInfoVO' => 'required|array',
                'channelInfoVO.channelName' => 'required|string',
            ]);

            if ($validator->fails()) {
                $errores[] = [
                    'index' => $index,
                    'errores' => $validator->errors()
                ];
                continue; // Saltar al siguiente si este tiene errores.
            }

            // 2. Mapear y guardar en la base de datos de destino.
            try {
                // Aquí usamos 'updateOrCreate' para evitar duplicados si el job se reintenta.
                // Necesitarás una columna única como 'record_id_origen' para guardar el 'recordId'.
                Fotomulta::updateOrCreate(
                    ['record_id_origen' => $deteccion['recordId']],
                    [
                        'placa' => $deteccion['plateNum'],
                        'velocidad_detectada' => $deteccion['carSpeed'],
                        'fecha_infraccion' => \Carbon\Carbon::parse($deteccion['capTime'])->toDateString(),
                        'hora_infraccion' => \Carbon\Carbon::parse($deteccion['capTime'])->toTimeString(),
                        'carril' => $deteccion['carWayCode'],
                        'nombre_radar' => $deteccion['channelInfoVO']['channelName'],
                        // ... completa aquí el mapeo de todos los demás campos ...
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