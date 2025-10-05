<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ApiKey extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'key',
        'user_id',
        'last_used_at',
        'expires_at',
        'is_active',
        'permissions',
        'ip_whitelist'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'permissions' => 'array',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'key', // Nunca exponer la key hasheada
    ];

    /**
     * Relación con el usuario propietario
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generar una nueva API Key
     *
     * @param string $name Nombre descriptivo
     * @param int $userId ID del usuario propietario
     * @param array|null $permissions Permisos opcionales
     * @param string|null $ipWhitelist IPs permitidas (separadas por coma)
     * @param \DateTime|null $expiresAt Fecha de expiración opcional
     * @return string La API Key sin hashear (solo se muestra una vez)
     */
    public static function generate(
        string $name,
        int $userId,
        ?array $permissions = null,
        ?string $ipWhitelist = null,
        ?\DateTime $expiresAt = null
    ): string {
        // Generar key aleatoria (43 caracteres)
        $plainKey = 'apk_' . Str::random(39);
        
        // Hashear para almacenamiento seguro
        $hashedKey = hash('sha256', $plainKey);

        // Crear registro
        self::create([
            'name' => $name,
            'key' => $hashedKey,
            'user_id' => $userId,
            'permissions' => $permissions,
            'ip_whitelist' => $ipWhitelist,
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        Log::channel('fotomultas')->info('API Key generated', [
            'name' => $name,
            'user_id' => $userId,
            'has_expiration' => !is_null($expiresAt),
            'has_ip_whitelist' => !is_null($ipWhitelist),
            'timestamp' => now()->toISOString()
        ]);

        // Retornar key sin hashear (solo se ve una vez)
        return $plainKey;
    }

    /**
     * Verificar si la API Key es válida
     */
    public function isValid(): bool
    {
        // Verificar si está activa
        if (!$this->is_active) {
            return false;
        }

        // Verificar si expiró
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Marcar la key como usada recientemente
     */
    public function markAsUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Verificar si la key tiene un permiso específico
     *
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        // Si no hay permisos definidos, tiene acceso a todo
        if (is_null($this->permissions)) {
            return true;
        }

        return in_array($permission, $this->permissions);
    }

    /**
     * Buscar API Key por su valor plano
     *
     * @param string $plainKey
     * @return self|null
     */
public static function findByPlainKey(string $plainKey): ?self
{
    $hashedKey = hash('sha256', $plainKey);
    
    // Debug logging
    Log::info('Buscando API Key', [
        'plain_key_prefix' => substr($plainKey, 0, 10),
        'hashed_key' => $hashedKey
    ]);
    
    $result = self::where('key', $hashedKey)->first();
    
    Log::info('Resultado búsqueda', [
        'found' => !is_null($result)
    ]);
    
    return $result;
}

    /**
     * Revocar (desactivar) la API Key
     */
    public function revoke(): bool
    {
        Log::channel('fotomultas')->warning('API Key revoked', [
            'name' => $this->name,
            'user_id' => $this->user_id,
            'timestamp' => now()->toISOString()
        ]);

        return $this->update(['is_active' => false]);
    }
}