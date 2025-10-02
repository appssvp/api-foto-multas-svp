<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fotomulta extends Model
{
    protected $table = 'fotomultas';
    
    protected $fillable = [
        'ticket_id', 
        'localida',
        'usuario_no',
        'folio',
        'placa',
        'fecha_infraccion',
        'hora_infraccion',
        'articulo',
        'fraccion',
        'parrafo',
        'motivacion',
        'calle',
        'entre_calle_1',
        'entre_calle_2',
        'colonia',
        'codigo_postal',
        'alcaldia',
        'velocidad_permitida',
        'velocidad_detectada',
        'nombre_radar',
        'geom_lat',
        'geom_lng',
        'carril',
        'tipo_vehiculo',
        'servicio_publico',
        'marca',
        'modelo',
        'color',
        'evidencia',
        'apellido_paterno_radar',
        'apellido_materno_radar',
        'img1',
        'img2',
        'img3',
        'modelo_dispositivo',
        'imei',
    ];

    protected $casts = [
        'fecha_infraccion'    => 'date',
        'servicio_publico'    => 'boolean',
        'velocidad_permitida' => 'integer',
        'velocidad_detectada' => 'integer',
        'geom_lat'            => 'decimal:7',
        'geom_lng'            => 'decimal:7',
        'carril'              => 'integer',
    ];
}