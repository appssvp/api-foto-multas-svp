<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fotomultas', function (Blueprint $table) {
            $table->id();
            // --- NUEVAS COLUMNAS ---
            $table->unsignedBigInteger('ticket_id')->unique()->nullable()->comment('ID del ticket en la API externa');
            $table->string('localida')->nullable()->comment('Nombre de la ubicación/cámara de la API');
            // USUARIO No
            $table->unsignedBigInteger('usuario_no')->nullable()->index();
            // FOLIO / PLACA
            $table->string('folio')->nullable()->unique();
            $table->string('placa', 20)->nullable()->index();
            // ... el resto de tus columnas ...
            $table->date('fecha_infraccion')->nullable();
            $table->time('hora_infraccion')->nullable();
            $table->string('articulo', 50)->nullable();
            $table->string('fraccion', 50)->nullable();
            $table->string('parrafo', 50)->nullable();
            $table->text('motivacion')->nullable();
            $table->string('calle')->nullable();
            $table->string('entre_calle_1')->nullable();
            $table->string('entre_calle_2')->nullable();
            $table->string('colonia')->nullable();
            $table->string('codigo_postal', 10)->nullable();
            $table->string('alcaldia')->nullable();
            $table->unsignedSmallInteger('velocidad_permitida')->nullable();
            $table->unsignedSmallInteger('velocidad_detectada')->nullable();
            $table->string('nombre_radar')->nullable();
            $table->decimal('geom_lat', 10, 7)->nullable();
            $table->decimal('geom_lng', 10, 7)->nullable();
            $table->string('tipo_vehiculo')->nullable();
            $table->boolean('servicio_publico')->nullable();
            $table->string('marca', 60)->nullable();
            $table->string('modelo', 60)->nullable();
            $table->string('color', 40)->nullable();
            $table->string('evidencia')->nullable();
            $table->string('apellido_paterno_radar', 80)->nullable();
            $table->string('apellido_materno_radar', 80)->nullable();
            $table->string('img1')->nullable();
            $table->string('img2')->nullable();
            $table->string('img3')->nullable();
            $table->string('modelo_dispositivo', 80)->nullable();
            $table->string('imei', 32)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fotomultas');
    }
};