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
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Nombre descriptivo: SIMUCI Producción');
            $table->string('key', 64)->unique()->comment('Key hasheada (SHA-256)');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->comment('Fecha de expiración opcional');
            $table->boolean('is_active')->default(true);
            $table->json('permissions')->nullable()->comment('Array de permisos: ["detecciones", "imagenes"]');
            $table->text('ip_whitelist')->nullable()->comment('IPs permitidas separadas por coma');
            $table->timestamps();
            
            // Índices para optimización
            $table->index('key');
            $table->index(['is_active', 'expires_at']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};