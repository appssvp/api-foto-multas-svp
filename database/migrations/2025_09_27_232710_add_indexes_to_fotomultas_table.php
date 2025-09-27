<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fotomultas', function (Blueprint $table) {
            // Índice para ticket_id (búsquedas por ID específico)
            $table->index('ticket_id', 'idx_ticket_id');
            
            // Índice para fecha_infraccion (filtros por fecha)
            $table->index('fecha_infraccion', 'idx_fecha_infraccion');
            
            // Índice compuesto para fecha_infraccion + hora_infraccion (ordenamiento por datetime)
            $table->index(['fecha_infraccion', 'hora_infraccion'], 'idx_fecha_hora');
            
            // Índice para placa (búsquedas por placa de vehículo)
            $table->index('placa', 'idx_placa');
            
            // Índice para localida (filtros por ubicación)
            $table->index('localida', 'idx_localida');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fotomultas', function (Blueprint $table) {
            // Eliminar índices en caso de rollback
            $table->dropIndex('idx_ticket_id');
            $table->dropIndex('idx_fecha_infraccion');
            $table->dropIndex('idx_fecha_hora');
            $table->dropIndex('idx_placa');
            $table->dropIndex('idx_localida');
        });
    }
};